<?php

namespace Bherila\GenAiLaravel\Tests\Feature;

use Bherila\GenAiLaravel\Contracts\MailboxAccessResolver;
use Bherila\GenAiLaravel\Exceptions\GenAiUnsupportedOperationException;
use Bherila\GenAiLaravel\GenAiRequest;
use Bherila\GenAiLaravel\GenAiServiceProvider;
use Bherila\GenAiLaravel\Mcp\EnqueueOptions;
use Bherila\GenAiLaravel\Mcp\Enums\McpRequestStatus;
use Bherila\GenAiLaravel\Mcp\Exceptions\McpQueueException;
use Bherila\GenAiLaravel\Mcp\ExecutionContext;
use Bherila\GenAiLaravel\Mcp\McpClientFactory;
use Bherila\GenAiLaravel\Mcp\McpQueueService;
use Bherila\GenAiLaravel\Mcp\Models\McpMailbox;
use Bherila\GenAiLaravel\Mcp\Models\McpRequest;
use Bherila\GenAiLaravel\Schema;
use Bherila\GenAiLaravel\ToolChoice;
use Bherila\GenAiLaravel\ToolConfig;
use Bherila\GenAiLaravel\ToolDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase;

final class McpQueueServiceTest extends TestCase
{
    use RefreshDatabase;

    private ExecutionContext $context;

    private TestMailboxAccessResolver $resolver;

    protected function getPackageProviders($app): array
    {
        return [GenAiServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('x', 32)));
        $app['config']->set('genai.mcp.enabled', true);
        $app['config']->set('genai.mcp.rest.enabled', true);
        $app['config']->set('genai.mcp.server.enabled', true);
        $app['config']->set('genai.mcp.server.allowed_hosts', ['localhost']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate')->run();
        Storage::fake('local');
        $this->context = new ExecutionContext('test-principal', [], ['genai:read', 'genai:work']);
        $this->resolver = new TestMailboxAccessResolver($this->context);
        $this->app->instance(MailboxAccessResolver::class, $this->resolver);
    }

    public function test_enqueue_claim_complete_and_replay_identical_completion(): void
    {
        $mailbox = $this->mailbox();
        $client = $this->app->make(McpClientFactory::class)->forMailbox($mailbox);
        $pending = GenAiRequest::with($client)->system('Extract one amount.')
            ->prompt('Read the invoice.')
            ->tools(new ToolConfig([
                new ToolDefinition('extract', 'Extract amount', Schema::object(['amount' => Schema::number()], ['amount'])),
            ], ToolChoice::tool('extract')))
            ->enqueue(new EnqueueOptions(idempotencyKey: 'invoice:1'));

        $service = $this->app->make(McpQueueService::class);
        $claim = $service->claim($this->context, idempotencyKey: 'claim:1');
        $this->assertSame($pending->id, $claim['request']['id']);
        $this->assertSame('extract', $claim['request']['submission_schema']['properties']['tool_calls']['items']['oneOf'][0]['properties']['name']['const']);

        $response = ['text' => '', 'tool_calls' => [['name' => 'extract', 'input' => ['amount' => 12.5]]]];
        $first = $service->complete($this->context, $pending->id, $claim['request']['lease_token'], $response, ['client' => 'test']);
        $second = $service->complete($this->context, $pending->id, $claim['request']['lease_token'], $response, ['client' => 'test']);
        $this->assertSame($first, $second);
        $this->assertSame(McpRequestStatus::Completed, $pending->status());
        $this->assertSame(12.5, $pending->response()?->firstToolCall()['input']['amount']);
        $this->assertDatabaseHas('genai_mcp_deliveries', ['request_id' => $pending->id, 'type' => 'completed']);
    }

    public function test_queued_client_rejects_synchronous_generate(): void
    {
        $client = $this->app->make(McpClientFactory::class)->forMailbox($this->mailbox());
        $this->expectException(GenAiUnsupportedOperationException::class);
        GenAiRequest::with($client)->prompt('Later')->generate();
    }

    public function test_claim_idempotency_replays_same_live_lease(): void
    {
        $pending = GenAiRequest::with($this->app->make(McpClientFactory::class)->forMailbox($this->mailbox()))->prompt('Hello')->enqueue();
        $service = $this->app->make(McpQueueService::class);
        $first = $service->claim($this->context, idempotencyKey: 'same-call');
        $second = $service->claim($this->context, idempotencyKey: 'same-call');
        $this->assertSame($pending->id, $second['request']['id']);
        $this->assertSame($first['request']['lease_token'], $second['request']['lease_token']);
        $this->assertSame(1, McpRequest::query()->find($pending->id)->attempt_count);
    }

    public function test_invalid_tool_input_is_rejected_without_consuming_lease(): void
    {
        $pending = GenAiRequest::with($this->app->make(McpClientFactory::class)->forMailbox($this->mailbox()))
            ->tools(new ToolConfig([new ToolDefinition('extract', 'Extract', Schema::object(['amount' => Schema::number()], ['amount']))], ToolChoice::any()))
            ->prompt('Extract')->enqueue();
        $service = $this->app->make(McpQueueService::class);
        $claim = $service->claim($this->context);
        try {
            $service->complete($this->context, $pending->id, $claim['request']['lease_token'], ['text' => '', 'tool_calls' => [['name' => 'extract', 'input' => ['amount' => 'wrong']]]]);
            $this->fail('Expected schema validation to reject completion.');
        } catch (McpQueueException $e) {
            $this->assertSame(422, $e->httpStatus);
        }
        $this->assertSame(McpRequestStatus::Leased, $pending->status());
    }

    public function test_inline_attachment_is_stored_and_streamed_over_signed_authenticated_rest(): void
    {
        $pending = GenAiRequest::with($this->app->make(McpClientFactory::class)->forMailbox($this->mailbox()))
            ->withFile(base64_encode('large-ish bytes'), 'application/pdf')->prompt('Read it')->enqueue();
        $claim = $this->app->make(McpQueueService::class)->claim($this->context);
        $attachment = $claim['request']['attachments'][0];
        $download = $this->get($attachment['download_url'])->assertOk();
        $this->assertSame('large-ish bytes', $download->streamedContent());
        $this->assertStringContainsString('no-store', (string) $download->headers->get('Cache-Control'));
        $this->assertStringNotContainsString(base64_encode('large-ish bytes'), json_encode(McpRequest::query()->find($pending->id)->payload));
    }

    public function test_retryable_failure_requeues_with_server_backoff(): void
    {
        $pending = GenAiRequest::with($this->app->make(McpClientFactory::class)->forMailbox($this->mailbox()))->prompt('Retry')->enqueue();
        $service = $this->app->make(McpQueueService::class);
        $claim = $service->claim($this->context);
        $result = $service->fail($this->context, $pending->id, $claim['request']['lease_token'], 'temporary', 'Try later', true);
        $this->assertSame('pending', $result['status']);
        $this->assertTrue(McpRequest::query()->find($pending->id)->available_at->isFuture());
    }

    public function test_standalone_endpoint_uses_official_streamable_http_protocol(): void
    {
        $this->mailbox();
        $response = $this->postJson('/genai/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-03-26', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']],
        ], ['Accept' => 'application/json, text/event-stream', 'Authorization' => 'Bearer test-token']);
        $response->assertOk()->assertJsonPath('result.serverInfo.name', 'GenAI subscription execution mailbox');
        $this->assertNotEmpty($response->headers->get('Mcp-Session-Id'));
    }

    private function mailbox(): McpMailbox
    {
        $mailbox = McpMailbox::query()->create(['owner_type' => 'user', 'owner_id' => '1', 'name' => 'default', 'enabled' => true]);
        $this->context = new ExecutionContext('test-principal', [$mailbox->id], ['genai:read', 'genai:work']);
        $this->resolver->context = $this->context;

        return $mailbox;
    }
}

final class TestMailboxAccessResolver implements MailboxAccessResolver
{
    public function __construct(public ExecutionContext $context) {}

    public function resolve(Request $request): ?ExecutionContext
    {
        return $this->context;
    }

    public function authorize(ExecutionContext $context, McpMailbox $mailbox, string $ability, ?McpRequest $request = null): bool
    {
        return $mailbox->enabled && in_array($mailbox->id, $context->mailboxIds, true) && $context->can($ability);
    }
}
