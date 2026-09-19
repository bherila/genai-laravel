<?php

namespace Bherila\GenAiLaravel\Tests\Feature;

use Bherila\GenAiLaravel\Contracts\MailboxAccessResolver;
use Bherila\GenAiLaravel\GenAiRequest;
use Bherila\GenAiLaravel\GenAiServiceProvider;
use Bherila\GenAiLaravel\Mcp\Enums\McpRequestStatus;
use Bherila\GenAiLaravel\Mcp\Exceptions\McpQueueException;
use Bherila\GenAiLaravel\Mcp\ExecutionContext;
use Bherila\GenAiLaravel\Mcp\Http\McpPreAuthGuard;
use Bherila\GenAiLaravel\Mcp\McpClientFactory;
use Bherila\GenAiLaravel\Mcp\McpQueueService;
use Bherila\GenAiLaravel\Mcp\Models\McpMailbox;
use Bherila\GenAiLaravel\Mcp\Models\McpRequest;
use Closure;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase;

/** Request hardening that must hold before authentication or payload work (#28, #43). */
final class McpHttpHardeningTest extends TestCase
{
    use RefreshDatabase;

    private CountingMailboxAccessResolver $resolver;

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
        $this->resolver = new CountingMailboxAccessResolver(null);
        $this->app->instance(MailboxAccessResolver::class, $this->resolver);
    }

    public function test_oversized_rest_bodies_are_refused_before_decoding_for_read_and_work_principals(): void
    {
        config(['genai.mcp.rest.max_body_bytes' => 1024]);
        $mailbox = $this->mailbox();
        $pending = GenAiRequest::with($this->app->make(McpClientFactory::class)->forMailbox($mailbox))->prompt('Hi')->enqueue();
        $work = new ExecutionContext('worker', [$mailbox->id], ['genai:read', 'genai:work']);
        $claim = $this->app->make(McpQueueService::class)->claim($work);
        $body = ['lease_token' => $claim['request']['lease_token'], 'response' => ['text' => str_repeat('x', 2048)]];

        foreach ([new ExecutionContext('reader', [$mailbox->id], ['genai:read']), $work] as $context) {
            $this->resolver->context = $context;
            $this->postJson('/genai/mcp/v1/requests/'.$pending->id.'/complete', $body)->assertStatus(413);
        }

        // No declared Content-Length, as with a chunked upload: the actual length still counts.
        $this->call('POST', '/genai/mcp/v1/requests/'.$pending->id.'/complete', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], (string) json_encode($body))->assertStatus(413);

        $this->assertSame(McpRequestStatus::Leased, $pending->status());
    }

    public function test_a_body_within_the_limit_still_completes(): void
    {
        config(['genai.mcp.rest.max_body_bytes' => 4096]);
        $mailbox = $this->mailbox();
        $pending = GenAiRequest::with($this->app->make(McpClientFactory::class)->forMailbox($mailbox))->prompt('Hi')->enqueue();
        $this->resolver->context = new ExecutionContext('worker', [$mailbox->id], ['genai:read', 'genai:work']);
        $claim = $this->app->make(McpQueueService::class)->claim($this->resolver->context);

        $this->postJson('/genai/mcp/v1/requests/'.$pending->id.'/complete', [
            'lease_token' => $claim['request']['lease_token'], 'response' => ['text' => 'done'],
        ])->assertOk()->assertJsonPath('status', 'completed');
    }

    public function test_a_read_only_principal_is_refused_before_its_completion_is_validated(): void
    {
        $mailbox = $this->mailbox();
        $pending = GenAiRequest::with($this->app->make(McpClientFactory::class)->forMailbox($mailbox))->prompt('Hi')->enqueue();
        $service = $this->app->make(McpQueueService::class);
        $claim = $service->claim(new ExecutionContext('worker', [$mailbox->id], ['genai:work']));

        try {
            // Invalid on every axis the validator checks; authorization must answer first.
            $service->complete(new ExecutionContext('reader', [$mailbox->id], ['genai:read']), $pending->id,
                $claim['request']['lease_token'], ['unknown' => true], ['client' => ['not-a-string']]);
            $this->fail('A read-only principal completed a request.');
        } catch (McpQueueException $e) {
            $this->assertSame(403, $e->httpStatus);
        }
        $this->assertSame(McpRequestStatus::Leased, $pending->status());
    }

    public function test_invalid_token_floods_are_limited_before_token_lookup_on_both_stacks(): void
    {
        config(['genai.mcp.rest.preauth_requests_per_minute' => 2, 'genai.mcp.rest.requests_per_minute' => 100]);

        foreach (['rest' => fn () => $this->getJson('/genai/mcp/v1/queue/status', ['Authorization' => 'Bearer bogus']),
            'server' => fn () => $this->postJson('/genai/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], [
                'Accept' => 'application/json, text/event-stream', 'Authorization' => 'Bearer bogus',
            ])] as $stack => $send) {
            $this->resolver->resolveCalls = 0;
            $this->app['cache']->flush();
            $send()->assertStatus(401);
            $send()->assertStatus(401);
            $send()->assertStatus(429);
            $this->assertSame(2, $this->resolver->resolveCalls, $stack.' looked up a token after the pre-auth limit.');
        }
    }

    public function test_the_preauth_limit_is_per_client_ip(): void
    {
        config(['genai.mcp.rest.preauth_requests_per_minute' => 1]);

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1'])->getJson('/genai/mcp/v1/queue/status')->assertStatus(401);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1'])->getJson('/genai/mcp/v1/queue/status')->assertStatus(429);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.2'])->getJson('/genai/mcp/v1/queue/status')->assertStatus(401);
    }

    public function test_the_guard_runs_before_global_input_transformers(): void
    {
        config(['genai.mcp.rest.max_body_bytes' => 1024]);
        $kernel = $this->app->make(HttpKernel::class);
        $kernel->pushMiddleware(RecordingGlobalMiddleware::class);
        RecordingGlobalMiddleware::$calls = 0;

        $this->postJson('/genai/mcp/v1/claims', ['queue' => str_repeat('x', 2048)])->assertStatus(413);
        $this->assertSame(0, RecordingGlobalMiddleware::$calls);

        $order = $kernel->getGlobalMiddleware();
        $guard = array_search(McpPreAuthGuard::class, $order, true);
        $this->assertSame(array_search(TrustProxies::class, $order, true) + 1, $guard);
        $this->assertLessThan(array_search(TrimStrings::class, $order, true), $guard);
    }

    public function test_the_rest_cap_follows_the_completion_limit_unless_set(): void
    {
        $guard = $this->app->make(McpPreAuthGuard::class);
        $send = fn (int $bytes): int => $guard->handle(
            Request::create('/genai/mcp/v1/claims', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], str_repeat('x', $bytes)),
            fn () => response('reached'),
        )->getStatusCode();
        config(['genai.mcp.rest.preauth_requests_per_minute' => 100, 'genai.mcp.rest.max_body_bytes' => null]);

        // A host that raised the completion limit keeps accepting completions of that size.
        config(['genai.mcp.limits.max_completion_bytes' => 2 * 1048576]);
        $this->assertSame(200, $send(2 * 1048576 + 1024));
        $this->assertSame(413, $send(4 * 1048576 + 65537));

        config(['genai.mcp.rest.max_body_bytes' => '4096']);
        $this->assertSame(200, $send(4096));
        $this->assertSame(413, $send(4097));
    }

    public function test_an_undeclared_oversized_body_is_read_only_one_byte_past_the_cap(): void
    {
        config(['genai.mcp.rest.max_body_bytes' => 1024]);
        stream_wrapper_register('genai-counting', CountingBodyStream::class);

        try {
            CountingBodyStream::$size = 1024 * 1024;
            CountingBodyStream::$read = 0;
            $request = Request::create('/genai/mcp/v1/claims', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], fopen('genai-counting://body', 'r'));
            $request->headers->remove('Content-Length');

            $response = $this->app->make(McpPreAuthGuard::class)->handle($request, fn () => response('reached'));

            $this->assertSame(413, $response->getStatusCode());
            // PHP streams read in 8 KiB chunks, so the bound is a chunk past the cap, not the whole body.
            $this->assertLessThanOrEqual(1025 + 8192, CountingBodyStream::$read);
        } finally {
            stream_wrapper_unregister('genai-counting');
        }
    }

    public function test_the_preauth_limit_keys_on_the_client_behind_a_trusted_proxy(): void
    {
        config(['genai.mcp.rest.preauth_requests_per_minute' => 1]);
        TrustProxies::at('*');

        try {
            $proxy = ['REMOTE_ADDR' => '10.0.0.1'];
            $this->withServerVariables($proxy + ['HTTP_X_FORWARDED_FOR' => '198.51.100.1'])->getJson('/genai/mcp/v1/queue/status')->assertStatus(401);
            $this->withServerVariables($proxy + ['HTTP_X_FORWARDED_FOR' => '198.51.100.1'])->getJson('/genai/mcp/v1/queue/status')->assertStatus(429);
            $this->withServerVariables($proxy + ['HTTP_X_FORWARDED_FOR' => '198.51.100.2'])->getJson('/genai/mcp/v1/queue/status')->assertStatus(401);
        } finally {
            TrustProxies::flushState();
        }
    }

    public function test_other_routes_are_not_limited_or_capped(): void
    {
        config(['genai.mcp.rest.preauth_requests_per_minute' => 1, 'genai.mcp.rest.max_body_bytes' => 16]);
        $this->app['router']->post('/host/endpoint', fn () => response()->json(['ok' => true]));

        $this->postJson('/host/endpoint', ['padding' => str_repeat('x', 64)])->assertOk();
        $this->postJson('/host/endpoint', ['padding' => str_repeat('x', 64)])->assertOk();
    }

    public function test_valid_principals_remain_bound_by_the_post_auth_limit(): void
    {
        config(['genai.mcp.rest.preauth_requests_per_minute' => 100, 'genai.mcp.rest.requests_per_minute' => 1]);
        $this->resolver->context = new ExecutionContext('worker', [$this->mailbox()->id], ['genai:read']);

        $this->getJson('/genai/mcp/v1/queue/status')->assertOk();
        $this->getJson('/genai/mcp/v1/queue/status')->assertStatus(429);
    }

    private function mailbox(): McpMailbox
    {
        return McpMailbox::query()->create(['owner_type' => 'user', 'owner_id' => '1', 'name' => 'default', 'enabled' => true]);
    }
}

/** A request body of $size bytes that records how many were actually read. */
final class CountingBodyStream
{
    public static int $size = 0;

    public static int $read = 0;

    /** @var resource|null */
    public $context;

    private int $position = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->position = 0;

        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = max(0, min($count, self::$size - $this->position));
        $this->position += $chunk;
        self::$read = max(self::$read, $this->position);

        return str_repeat('x', $chunk);
    }

    public function stream_eof(): bool
    {
        return $this->position >= self::$size;
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        $this->position = $offset;

        return true;
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return ['size' => self::$size];
    }
}

final class RecordingGlobalMiddleware
{
    public static int $calls = 0;

    public function handle(Request $request, Closure $next): mixed
    {
        self::$calls++;

        return $next($request);
    }
}

final class CountingMailboxAccessResolver implements MailboxAccessResolver
{
    public int $resolveCalls = 0;

    public function __construct(public ?ExecutionContext $context) {}

    public function resolve(Request $request): ?ExecutionContext
    {
        $this->resolveCalls++;

        return $this->context;
    }

    public function authorize(ExecutionContext $context, McpMailbox $mailbox, string $ability, ?McpRequest $request = null): bool
    {
        return $mailbox->enabled && in_array($mailbox->id, $context->mailboxIds, true) && $context->can($ability);
    }
}
