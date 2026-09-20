<?php

namespace Bherila\GenAiLaravel\Tests\Feature;

use Bherila\GenAiLaravel\ContentBlock;
use Bherila\GenAiLaravel\Contracts\CompletionDelivery;
use Bherila\GenAiLaravel\Contracts\MailboxAccessResolver;
use Bherila\GenAiLaravel\Exceptions\GenAiUnsupportedOperationException;
use Bherila\GenAiLaravel\GenAiRequest;
use Bherila\GenAiLaravel\GenAiServiceProvider;
use Bherila\GenAiLaravel\Mcp\Auth\PersonalTokenMailboxAccessResolver;
use Bherila\GenAiLaravel\Mcp\EnqueueOptions;
use Bherila\GenAiLaravel\Mcp\Enums\McpRequestStatus;
use Bherila\GenAiLaravel\Mcp\Events\McpRequestQueued;
use Bherila\GenAiLaravel\Mcp\Exceptions\McpQueueException;
use Bherila\GenAiLaravel\Mcp\ExecutionContext;
use Bherila\GenAiLaravel\Mcp\McpClientFactory;
use Bherila\GenAiLaravel\Mcp\McpQueueService;
use Bherila\GenAiLaravel\Mcp\McpTokenService;
use Bherila\GenAiLaravel\Mcp\Models\McpDelivery;
use Bherila\GenAiLaravel\Mcp\Models\McpMailbox;
use Bherila\GenAiLaravel\Mcp\Models\McpRequest;
use Bherila\GenAiLaravel\Mcp\PendingGenAiRequest;
use Bherila\GenAiLaravel\Schema;
use Bherila\GenAiLaravel\ToolChoice;
use Bherila\GenAiLaravel\ToolConfig;
use Bherila\GenAiLaravel\ToolDefinition;
use Bherila\McpLaravelBridge\Http\McpHttpSecurityMiddleware;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;

final class McpQueueServiceTest extends TestCase
{
    use RefreshDatabase;

    private ExecutionContext $context;

    private TestMailboxAccessResolver $resolver;

    private ?string $mcpSessionId = null;

    private int $mcpRequestId = 1;

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
        $this->assertArrayNotHasKey('anyOf', $claim['request']['submission_schema']);

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

    public function test_claim_idempotency_rejects_a_different_queue_filter(): void
    {
        $client = $this->app->make(McpClientFactory::class)->forMailbox($this->mailbox());
        GenAiRequest::with($client)->prompt('Alpha')->enqueue(new EnqueueOptions(queue: 'alpha'));
        GenAiRequest::with($client)->prompt('Beta')->enqueue(new EnqueueOptions(queue: 'beta'));
        $service = $this->app->make(McpQueueService::class);
        $service->claim($this->context, queue: 'alpha', idempotencyKey: 'scheduled-run');

        try {
            $service->claim($this->context, queue: 'beta', idempotencyKey: 'scheduled-run');
            $this->fail('Expected claim idempotency to be bound to the original queue filter.');
        } catch (McpQueueException $exception) {
            $this->assertSame(409, $exception->httpStatus);
        }
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

    public function test_prune_finalizes_an_exhausted_expired_lease_without_another_claim(): void
    {
        config(['genai.mcp.lease.seconds' => 10]);
        $pending = GenAiRequest::with($this->app->make(McpClientFactory::class)->forMailbox($this->mailbox()))
            ->prompt('Only once')->enqueue(new EnqueueOptions(maxAttempts: 1));
        $this->app->make(McpQueueService::class)->claim($this->context);
        $this->travel(11)->seconds();

        $this->artisan('genai:mcp:prune')->assertSuccessful();

        $this->assertSame(McpRequestStatus::Failed, $pending->status());
        $this->assertDatabaseHas('genai_mcp_deliveries', ['request_id' => $pending->id, 'type' => 'failed']);
    }

    public function test_prune_transactionally_expires_request_level_deadlines(): void
    {
        $pending = GenAiRequest::with($this->app->make(McpClientFactory::class)->forMailbox($this->mailbox()))
            ->prompt('Deadline')->enqueue(new EnqueueOptions(expiresAt: now()->addSecond()));
        $this->travel(2)->seconds();

        $this->artisan('genai:mcp:prune')->assertSuccessful();

        $this->assertSame(McpRequestStatus::Expired, $pending->status());
        $this->assertNull(McpRequest::query()->findOrFail($pending->id)->lease_principal);
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

    public function test_mcp_completion_preserves_an_empty_json_object_tool_input(): void
    {
        $pending = GenAiRequest::with($this->app->make(McpClientFactory::class)->forMailbox($this->mailbox()))
            ->tools(new ToolConfig([new ToolDefinition('ping', 'No input', Schema::object([]))], ToolChoice::any()))
            ->prompt('Ping')->enqueue();
        $claim = $this->app->make(McpQueueService::class)->claim($this->context);
        $headers = ['Accept' => 'application/json, text/event-stream', 'Authorization' => 'Bearer test-token'];
        $initialize = $this->postJson('/genai/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-03-26', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']],
        ], $headers)->assertOk();
        $headers['Mcp-Session-Id'] = $initialize->headers->get('Mcp-Session-Id');
        $headers['Mcp-Protocol-Version'] = '2025-03-26';

        $this->postJson('/genai/mcp', [
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
            'params' => ['name' => 'complete_genai_request', 'arguments' => [
                'request_id' => $pending->id,
                'lease_token' => $claim['request']['lease_token'],
                'response' => ['tool_calls' => [['name' => 'ping', 'input' => new \stdClass]]],
            ]],
        ], $headers)->assertOk()->assertJsonPath('result.structuredContent.status', 'completed');
        $this->assertSame(McpRequestStatus::Completed, $pending->status());
    }

    public function test_rest_completion_preserves_an_empty_json_object_tool_input(): void
    {
        $pending = GenAiRequest::with($this->app->make(McpClientFactory::class)->forMailbox($this->mailbox()))
            ->tools(new ToolConfig([new ToolDefinition('ping', 'No input', Schema::object([]))], ToolChoice::any()))
            ->prompt('Ping')->enqueue();
        $claim = $this->app->make(McpQueueService::class)->claim($this->context);

        $this->postJson('/genai/mcp/v1/requests/'.$pending->id.'/complete', [
            'lease_token' => $claim['request']['lease_token'],
            'response' => ['tool_calls' => [['name' => 'ping', 'input' => new \stdClass]]],
        ])->assertOk()->assertJsonPath('status', 'completed');
    }

    public function test_rest_receipt_reports_an_empty_tool_input_as_a_json_object(): void
    {
        $pending = $this->pendingWithNoArgumentTool();
        $claim = $this->app->make(McpQueueService::class)->claim($this->context);
        $body = ['lease_token' => $claim['request']['lease_token'],
            'response' => ['tool_calls' => [['name' => 'ping', 'input' => new \stdClass]]]];

        $first = $this->postJson('/genai/mcp/v1/requests/'.$pending->id.'/complete', $body)->assertOk();
        $replay = $this->postJson('/genai/mcp/v1/requests/'.$pending->id.'/complete', $body)->assertOk();
        $status = $this->getJson('/genai/mcp/v1/requests/'.$pending->id)->assertOk();

        foreach ([$first, $replay] as $response) {
            $this->assertStringContainsString('"input":{}', $response->getContent());
        }
        $this->assertStringContainsString('"input":{}', $status->getContent());
    }

    public function test_mcp_receipt_reports_an_empty_tool_input_as_a_json_object(): void
    {
        $pending = $this->pendingWithNoArgumentTool();
        $claim = $this->app->make(McpQueueService::class)->claim($this->context);
        $arguments = ['request_id' => $pending->id, 'lease_token' => $claim['request']['lease_token'],
            'response' => ['tool_calls' => [['name' => 'ping', 'input' => new \stdClass]]]];

        $first = $this->mcpToolCall('complete_genai_request', $arguments)->assertOk();
        $replay = $this->mcpToolCall('complete_genai_request', $arguments)->assertOk();

        // Asserted on the raw body: decoding it here would itself turn the
        // empty object into an empty list and hide the shape under test.
        foreach ([$first, $replay] as $response) {
            $this->assertStringContainsString('"input":{}', $response->getContent());
        }
    }

    private function pendingWithNoArgumentTool(): PendingGenAiRequest
    {
        return GenAiRequest::with($this->app->make(McpClientFactory::class)->forMailbox($this->mailbox()))
            ->tools(new ToolConfig([new ToolDefinition('ping', 'No input', Schema::object([]))], ToolChoice::any()))
            ->prompt('Ping')->enqueue();
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

    public function test_enqueue_rolls_back_when_attachment_storage_rejects_a_write(): void
    {
        $disk = \Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->once()->andReturnFalse();
        $disk->shouldReceive('deleteDirectory')->once()->andReturnTrue();
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);

        try {
            GenAiRequest::with($this->app->make(McpClientFactory::class)->forMailbox($this->mailbox()))
                ->withFile(base64_encode('bytes'), 'application/pdf')->prompt('Read it')->enqueue();
            $this->fail('Expected the failed storage write to abort enqueue.');
        } catch (McpQueueException $exception) {
            $this->assertSame(503, $exception->httpStatus);
        }
        $this->assertDatabaseCount('genai_mcp_requests', 0);
        $this->assertDatabaseCount('genai_mcp_attachments', 0);
    }

    public function test_post_commit_listener_failure_does_not_delete_committed_attachments(): void
    {
        Event::listen(McpRequestQueued::class, static function (): never {
            throw new \RuntimeException('listener failed after commit');
        });

        try {
            GenAiRequest::with($this->app->make(McpClientFactory::class)->forMailbox($this->mailbox()))
                ->withFile(base64_encode('durable bytes'), 'application/pdf')->prompt('Read it')->enqueue();
            $this->fail('Expected the post-commit listener exception to reach the producer.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('listener failed after commit', $exception->getMessage());
        }

        $request = McpRequest::query()->with('attachments')->firstOrFail();
        $attachment = $request->attachments->firstOrFail();
        Storage::disk((string) $attachment->disk)->assertExists((string) $attachment->path);
    }

    public function test_empty_historical_tool_input_is_emitted_as_a_json_object(): void
    {
        GenAiRequest::with($this->app->make(McpClientFactory::class)->forMailbox($this->mailbox()))
            ->messages([['role' => 'assistant', 'content' => [ContentBlock::toolCall('call-1', 'ping', [])]]])
            ->enqueue();

        $claim = $this->app->make(McpQueueService::class)->claim($this->context);
        $encoded = json_encode($claim['request']['input']['messages'][0]['content'][0], JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('"input":{}', $encoded);
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

    public function test_standalone_catalog_advertises_exact_lifecycle_annotations(): void
    {
        $this->mailbox();
        $headers = ['Accept' => 'application/json, text/event-stream', 'Authorization' => 'Bearer test-token'];
        $initialize = $this->postJson('/genai/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-03-26', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']],
        ], $headers)->assertOk();
        $headers['Mcp-Session-Id'] = $initialize->headers->get('Mcp-Session-Id');
        $headers['Mcp-Protocol-Version'] = '2025-03-26';

        $tools = $this->postJson('/genai/mcp', [
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => new \stdClass,
        ], $headers)->assertOk()->json('result.tools');

        $this->assertSame([
            'genai_queue_status' => [
                'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false,
            ],
            'claim_genai_request' => [
                'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false,
            ],
            'renew_genai_lease' => [
                'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false,
            ],
            'complete_genai_request' => [
                'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false,
            ],
            'fail_genai_request' => [
                'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false,
            ],
        ], collect($tools)->mapWithKeys(static fn (array $tool): array => [$tool['name'] => $tool['annotations']])->all());
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

    public function test_mcp_completion_replay_ignores_nested_object_member_order(): void
    {
        $pending = $this->pendingWithNestedObjectTool();
        $claim = $this->app->make(McpQueueService::class)->claim($this->context);
        $token = $claim['request']['lease_token'];

        $first = $this->mcpToolCall('complete_genai_request', [
            'request_id' => $pending->id, 'lease_token' => $token,
            'response' => ['tool_calls' => [['name' => 'record', 'input' => ['fields' => ['b' => '2', 'a' => '1']]]]],
        ])->assertOk();
        $replay = $this->mcpToolCall('complete_genai_request', [
            'request_id' => $pending->id, 'lease_token' => $token,
            'response' => ['tool_calls' => [['name' => 'record', 'input' => ['fields' => ['a' => '1', 'b' => '2']]]]],
        ])->assertOk();

        $this->assertSame(
            $first->json('result.structuredContent.receipt_id'),
            $replay->json('result.structuredContent.receipt_id'),
        );
    }

    public function test_rest_completion_replay_ignores_nested_object_member_order(): void
    {
        $pending = $this->pendingWithNestedObjectTool();
        $claim = $this->app->make(McpQueueService::class)->claim($this->context);
        $token = $claim['request']['lease_token'];

        $first = $this->postJson('/genai/mcp/v1/requests/'.$pending->id.'/complete', [
            'lease_token' => $token,
            'response' => ['tool_calls' => [['name' => 'record', 'input' => ['fields' => ['b' => '2', 'a' => '1']]]]],
        ])->assertOk();
        $replay = $this->postJson('/genai/mcp/v1/requests/'.$pending->id.'/complete', [
            'lease_token' => $token,
            'response' => ['tool_calls' => [['name' => 'record', 'input' => ['fields' => ['a' => '1', 'b' => '2']]]]],
        ])->assertOk();

        $this->assertSame($first->json('receipt_id'), $replay->json('receipt_id'));
    }

    public function test_completion_replay_with_different_nested_values_still_conflicts(): void
    {
        $pending = $this->pendingWithNestedObjectTool();
        $claim = $this->app->make(McpQueueService::class)->claim($this->context);
        $token = $claim['request']['lease_token'];

        $this->postJson('/genai/mcp/v1/requests/'.$pending->id.'/complete', [
            'lease_token' => $token,
            'response' => ['tool_calls' => [['name' => 'record', 'input' => ['fields' => ['a' => '1', 'b' => '2']]]]],
        ])->assertOk();
        $this->postJson('/genai/mcp/v1/requests/'.$pending->id.'/complete', [
            'lease_token' => $token,
            'response' => ['tool_calls' => [['name' => 'record', 'input' => ['fields' => ['a' => '1', 'b' => '3']]]]],
        ])->assertStatus(409);
    }

    public function test_completion_replay_ignores_numeric_keyed_nested_member_order(): void
    {
        // Numeric-keyed members survive both transports as JSON objects rather
        // than property maps, so only recursive canonicalization sorts them.
        $pending = GenAiRequest::with($this->app->make(McpClientFactory::class)->forMailbox($this->mailbox()))
            ->tools(new ToolConfig([new ToolDefinition('record', 'Record fields', Schema::object([
                'fields' => Schema::fromArray(['type' => 'object']),
            ], ['fields']))], ToolChoice::any()))
            ->prompt('Record it')->enqueue();
        $claim = $this->app->make(McpQueueService::class)->claim($this->context);
        $token = $claim['request']['lease_token'];

        $first = $this->postJson('/genai/mcp/v1/requests/'.$pending->id.'/complete', [
            'lease_token' => $token,
            'response' => ['tool_calls' => [['name' => 'record', 'input' => ['fields' => ['2' => 'b', '1' => 'a']]]]],
        ])->assertOk();
        $replay = $this->postJson('/genai/mcp/v1/requests/'.$pending->id.'/complete', [
            'lease_token' => $token,
            'response' => ['tool_calls' => [['name' => 'record', 'input' => ['fields' => ['1' => 'a', '2' => 'b']]]]],
        ])->assertOk();

        $this->assertSame($first->json('receipt_id'), $replay->json('receipt_id'));
    }

    public function test_mcp_completion_replay_ignores_numeric_keyed_nested_member_order(): void
    {
        $pending = GenAiRequest::with($this->app->make(McpClientFactory::class)->forMailbox($this->mailbox()))
            ->tools(new ToolConfig([new ToolDefinition('record', 'Record fields', Schema::object([
                'fields' => Schema::fromArray(['type' => 'object']),
            ], ['fields']))], ToolChoice::any()))
            ->prompt('Record it')->enqueue();
        $claim = $this->app->make(McpQueueService::class)->claim($this->context);
        $token = $claim['request']['lease_token'];

        $first = $this->mcpToolCall('complete_genai_request', [
            'request_id' => $pending->id, 'lease_token' => $token,
            'response' => ['tool_calls' => [['name' => 'record', 'input' => ['fields' => ['2' => 'b', '1' => 'a']]]]],
        ])->assertOk();
        $replay = $this->mcpToolCall('complete_genai_request', [
            'request_id' => $pending->id, 'lease_token' => $token,
            'response' => ['tool_calls' => [['name' => 'record', 'input' => ['fields' => ['1' => 'a', '2' => 'b']]]]],
        ])->assertOk();

        $this->assertSame(
            $first->json('result.structuredContent.receipt_id'),
            $replay->json('result.structuredContent.receipt_id'),
        );
    }

    public function test_enqueue_idempotency_ignores_nested_metadata_member_order(): void
    {
        $mailbox = $this->mailbox();
        $client = $this->app->make(McpClientFactory::class)->forMailbox($mailbox);
        $first = GenAiRequest::with($client)->prompt('Read it')
            ->enqueue(new EnqueueOptions(idempotencyKey: 'invoice:9', metadata: ['trace' => ['b' => '2', 'a' => '1']]));
        $second = GenAiRequest::with($client)->prompt('Read it')
            ->enqueue(new EnqueueOptions(idempotencyKey: 'invoice:9', metadata: ['trace' => ['a' => '1', 'b' => '2']]));

        $this->assertSame($first->id, $second->id);
    }

    private function pendingWithNestedObjectTool(): PendingGenAiRequest
    {
        return GenAiRequest::with($this->app->make(McpClientFactory::class)->forMailbox($this->mailbox()))
            ->tools(new ToolConfig([new ToolDefinition('record', 'Record fields', Schema::object([
                'fields' => Schema::object(['a' => Schema::string(), 'b' => Schema::string()]),
            ], ['fields']))], ToolChoice::any()))
            ->prompt('Record it')->enqueue();
    }

    /**
     * Drive one tool call over the standalone MCP endpoint, where nested JSON
     * members reach the queue as objects rather than as property maps.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function mcpToolCall(string $name, array $arguments): TestResponse
    {
        $headers = ['Accept' => 'application/json, text/event-stream', 'Authorization' => 'Bearer test-token'];
        if ($this->mcpSessionId === null) {
            $initialize = $this->postJson('/genai/mcp', [
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
                'params' => ['protocolVersion' => '2025-03-26', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']],
            ], $headers)->assertOk();
            $this->mcpSessionId = $initialize->headers->get('Mcp-Session-Id');
        }
        $headers['Mcp-Session-Id'] = $this->mcpSessionId;
        $headers['Mcp-Protocol-Version'] = '2025-03-26';

        return $this->postJson('/genai/mcp', [
            'jsonrpc' => '2.0', 'id' => ++$this->mcpRequestId, 'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ], $headers);
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
