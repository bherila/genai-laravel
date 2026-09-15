<?php

namespace Bherila\GenAiLaravel\Tests\Feature;

use Bherila\GenAiLaravel\Contracts\CompletionDelivery;
use Bherila\GenAiLaravel\Contracts\MailboxAccessResolver;
use Bherila\GenAiLaravel\Exceptions\GenAiUnsupportedOperationException;
use Bherila\GenAiLaravel\GenAiRequest;
use Bherila\GenAiLaravel\GenAiServiceProvider;
use Bherila\GenAiLaravel\Mcp\Auth\PersonalTokenMailboxAccessResolver;
use Bherila\GenAiLaravel\Mcp\EnqueueOptions;
use Bherila\GenAiLaravel\Mcp\Enums\McpRequestStatus;
use Bherila\GenAiLaravel\Mcp\Exceptions\McpQueueException;
use Bherila\GenAiLaravel\Mcp\ExecutionContext;
use Bherila\GenAiLaravel\Mcp\McpClientFactory;
use Bherila\GenAiLaravel\Mcp\McpQueueService;
use Bherila\GenAiLaravel\Mcp\McpTokenService;
use Bherila\GenAiLaravel\Mcp\Models\McpDelivery;
use Bherila\GenAiLaravel\Mcp\Models\McpMailbox;
use Bherila\GenAiLaravel\Mcp\Models\McpRequest;
use Bherila\GenAiLaravel\Schema;
use Bherila\GenAiLaravel\ToolChoice;
use Bherila\GenAiLaravel\ToolConfig;
use Bherila\GenAiLaravel\ToolDefinition;
use Bherila\McpLaravelBridge\Http\McpHttpSecurityMiddleware;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;

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
        $app['config']->set('genai.mcp.server.allowed_origins', ['https://client.example']);
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
        $this->assertNotSame($first['request']['lease_token'], McpRequest::query()->find($pending->id)->lease_token_hash);
    }

    public function test_expired_lease_is_reclaimed_and_old_executor_cannot_complete(): void
    {
        config(['genai.mcp.lease.seconds' => 10]);
        $pending = GenAiRequest::with($this->app->make(McpClientFactory::class)->forMailbox($this->mailbox()))->prompt('Recover me')->enqueue();
        $service = $this->app->make(McpQueueService::class);
        $first = $service->claim($this->context);
        $this->travel(11)->seconds();
        $second = $service->claim($this->context);

        $this->assertSame($pending->id, $second['request']['id']);
        $this->assertNotSame($first['request']['lease_token'], $second['request']['lease_token']);
        $this->expectException(McpQueueException::class);
        $service->complete($this->context, $pending->id, $first['request']['lease_token'], ['text' => 'stale']);
    }

    public function test_access_loss_is_rechecked_during_a_live_lease(): void
    {
        $pending = GenAiRequest::with($this->app->make(McpClientFactory::class)->forMailbox($this->mailbox()))->prompt('Private')->enqueue();
        $service = $this->app->make(McpQueueService::class);
        $claim = $service->claim($this->context);
        $this->resolver->deniedRequestIds[] = $pending->id;

        try {
            $service->complete($this->context, $pending->id, $claim['request']['lease_token'], ['text' => 'should fail']);
            $this->fail('Expected current job authorization to be enforced.');
        } catch (McpQueueException $exception) {
            $this->assertSame(403, $exception->httpStatus);
        }
        $this->assertSame(McpRequestStatus::Leased, $pending->status());
        $this->assertSame(0, $service->status($this->context)['leased']);
    }

    public function test_mailbox_isolation_and_different_completion_replay_conflicts(): void
    {
        $mailbox = $this->mailbox();
        $pending = GenAiRequest::with($this->app->make(McpClientFactory::class)->forMailbox($mailbox))->prompt('One')->enqueue();
        $service = $this->app->make(McpQueueService::class);
        $claim = $service->claim($this->context);
        $service->complete($this->context, $pending->id, $claim['request']['lease_token'], ['text' => 'first']);

        try {
            $service->complete($this->context, $pending->id, $claim['request']['lease_token'], ['text' => 'different']);
            $this->fail('Expected a conflicting completion replay to be rejected.');
        } catch (McpQueueException $exception) {
            $this->assertSame(409, $exception->httpStatus);
        }

        $other = new ExecutionContext('other-user', [], ['genai:read', 'genai:work']);
        try {
            $service->findAuthorized($other, $pending->id);
            $this->fail('Expected a cross-mailbox lookup to remain opaque.');
        } catch (McpQueueException $exception) {
            $this->assertSame(404, $exception->httpStatus);
        }
    }

    public function test_last_expired_attempt_becomes_a_durable_terminal_failure(): void
    {
        config(['genai.mcp.lease.seconds' => 10]);
        $pending = GenAiRequest::with($this->app->make(McpClientFactory::class)->forMailbox($this->mailbox()))
            ->prompt('Only once')->enqueue(new EnqueueOptions(maxAttempts: 1));
        $service = $this->app->make(McpQueueService::class);
        $service->claim($this->context);
        $this->travel(11)->seconds();

        $this->assertNull($service->claim($this->context));
        $this->assertSame(McpRequestStatus::Failed, $pending->status());
        $this->assertDatabaseHas('genai_mcp_deliveries', ['request_id' => $pending->id, 'type' => 'failed']);
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

    public function test_auto_submission_requires_nonempty_text_or_a_defined_tool_call(): void
    {
        $pending = GenAiRequest::with($this->app->make(McpClientFactory::class)->forMailbox($this->mailbox()))->prompt('Answer')->enqueue();
        $service = $this->app->make(McpQueueService::class);
        $claim = $service->claim($this->context);
        try {
            $service->complete($this->context, $pending->id, $claim['request']['lease_token'], []);
            $this->fail('Expected an empty automatic completion to be rejected.');
        } catch (McpQueueException $exception) {
            $this->assertSame(422, $exception->httpStatus);
        }
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

    public function test_mcp_and_rest_claims_return_the_same_canonical_envelope(): void
    {
        $pending = GenAiRequest::with($this->app->make(McpClientFactory::class)->forMailbox($this->mailbox()))->prompt('Same payload')->enqueue();
        $headers = ['Accept' => 'application/json, text/event-stream', 'Authorization' => 'Bearer test-token'];
        $initialize = $this->postJson('/genai/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-03-26', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']],
        ], $headers)->assertOk();
        $headers['Mcp-Session-Id'] = $initialize->headers->get('Mcp-Session-Id');
        $headers['Mcp-Protocol-Version'] = '2025-03-26';
        $mcp = $this->postJson('/genai/mcp', [
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
            'params' => ['name' => 'claim_genai_request', 'arguments' => ['idempotency_key' => 'cross-transport']],
        ], $headers)->assertOk()->json('result.structuredContent');
        $rest = $this->postJson('/genai/mcp/v1/claims', [], ['Idempotency-Key' => 'cross-transport'])->assertOk()->json();

        $this->assertSame($pending->id, $mcp['request']['id']);
        $this->assertSame($rest, $mcp);
    }

    public function test_standalone_edge_rejects_invalid_host_origin_and_query_credentials(): void
    {
        $middleware = $this->app->make(McpHttpSecurityMiddleware::class);
        $next = static fn (Request $_request): JsonResponse => new JsonResponse(['ok' => true]);
        $invalidHost = Request::create('/genai/mcp', 'POST', server: ['HTTP_HOST' => 'attacker.example'], content: '{}');
        $invalidOrigin = Request::create('/genai/mcp', 'POST', server: ['HTTP_HOST' => 'localhost', 'HTTP_ORIGIN' => 'https://attacker.example'], content: '{}');
        $queryCredential = Request::create('/genai/mcp?access_token=secret', 'POST', server: ['HTTP_HOST' => 'localhost'], content: '{}');
        $preflight = Request::create('/genai/mcp', 'OPTIONS', server: [
            'HTTP_HOST' => 'localhost',
            'HTTP_ORIGIN' => 'https://client.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'Authorization, Content-Type',
        ]);

        $this->assertSame(403, $middleware->handle($invalidHost, $next)->getStatusCode());
        $this->assertSame(403, $middleware->handle($invalidOrigin, $next)->getStatusCode());
        $this->assertSame(400, $middleware->handle($queryCredential, $next)->getStatusCode());
        $response = $middleware->handle($preflight, $next);
        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('https://client.example', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_optional_personal_token_is_stored_only_as_a_hash(): void
    {
        config(['genai.mcp.personal_tokens.enabled' => true]);
        $mailbox = $this->mailbox();
        $plain = $this->app->make(McpTokenService::class)->issue($mailbox, 'Codex laptop');
        $this->assertStringStartsWith('genai_mcp_', $plain);
        $this->assertDatabaseMissing('genai_mcp_tokens', ['token_hash' => $plain]);
        $this->assertDatabaseHas('genai_mcp_tokens', ['token_hash' => hash('sha256', $plain)]);

        $request = Request::create('/genai/mcp/v1/queue/status', 'GET', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$plain]);
        $context = (new PersonalTokenMailboxAccessResolver)->resolve($request);
        $this->assertSame([$mailbox->id], $context?->mailboxIds);
    }

    public function test_durable_delivery_is_acknowledged_only_after_host_application_succeeds(): void
    {
        $pending = GenAiRequest::with($this->app->make(McpClientFactory::class)->forMailbox($this->mailbox()))->prompt('Answer')->enqueue();
        $service = $this->app->make(McpQueueService::class);
        $claim = $service->claim($this->context);
        $service->complete($this->context, $pending->id, $claim['request']['lease_token'], ['text' => 'Done']);
        $handler = new RecordingCompletionDelivery;
        $this->app->instance(CompletionDelivery::class, $handler);
        $this->artisan('genai:mcp:deliver')->assertSuccessful();
        $delivery = McpDelivery::query()->where('request_id', $pending->id)->firstOrFail();
        $this->assertSame([$delivery->id], $handler->seen);
        $this->assertNotNull($delivery->acknowledged_at);
    }

    private function mailbox(): McpMailbox
    {
        $mailbox = McpMailbox::query()->create(['owner_type' => 'user', 'owner_id' => '1', 'name' => 'default', 'enabled' => true]);
        $this->context = new ExecutionContext('test-principal', [$mailbox->id], ['genai:read', 'genai:work']);
        $this->resolver->context = $this->context;

        return $mailbox;
    }
}

final class RecordingCompletionDelivery implements CompletionDelivery
{
    /** @var list<string> */
    public array $seen = [];

    public function deliver(McpDelivery $delivery): bool
    {
        $this->seen[] = $delivery->id;

        return true;
    }
}

final class TestMailboxAccessResolver implements MailboxAccessResolver
{
    /** @var list<string> */
    public array $deniedRequestIds = [];

    public function __construct(public ExecutionContext $context) {}

    public function resolve(Request $request): ?ExecutionContext
    {
        return $this->context;
    }

    public function authorize(ExecutionContext $context, McpMailbox $mailbox, string $ability, ?McpRequest $request = null): bool
    {
        return $mailbox->enabled
            && ($request === null || ! in_array($request->id, $this->deniedRequestIds, true))
            && in_array($mailbox->id, $context->mailboxIds, true)
            && $context->can($ability);
    }
}
