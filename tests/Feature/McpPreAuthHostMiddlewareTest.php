<?php

namespace Bherila\GenAiLaravel\Tests\Feature;

use Bherila\GenAiLaravel\GenAiServiceProvider;
use Closure;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase;

/**
 * The router's middleware priority sorts anything implementing
 * AuthenticatesRequests (a host's `auth:*`) ahead of `throttle:`, whatever the
 * array order, so the pre-auth limit has to run before routing to hold.
 */
final class McpPreAuthHostMiddlewareTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [GenAiServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('x', 32)));
        $app['config']->set('genai.mcp.enabled', true);
        $app['config']->set('genai.mcp.server.enabled', true);
        $app['config']->set('genai.mcp.server.allowed_hosts', ['localhost']);
        $app['config']->set('genai.mcp.rest.preauth_requests_per_minute', 2);
        $app['config']->set('genai.mcp.rest.middleware', [RejectingHostAuthentication::class]);
        $app['config']->set('genai.mcp.server.middleware', [RejectingHostAuthentication::class]);
    }

    public function test_prioritized_host_authentication_cannot_run_ahead_of_the_preauth_limit(): void
    {
        foreach (['rest' => fn () => $this->getJson('/genai/mcp/v1/queue/status'),
            'server' => fn () => $this->postJson('/genai/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], ['Accept' => 'application/json, text/event-stream'])] as $stack => $send) {
            RejectingHostAuthentication::$calls = 0;
            $this->app['cache']->flush();
            $send()->assertStatus(401);
            $send()->assertStatus(401);
            $send()->assertStatus(429);
            $this->assertSame(2, RejectingHostAuthentication::$calls, $stack.' authenticated a request after the pre-auth limit.');
        }
    }
}

final class RejectingHostAuthentication implements AuthenticatesRequests
{
    public static int $calls = 0;

    public function handle(Request $request, Closure $next): mixed
    {
        self::$calls++;

        return new JsonResponse(['message' => 'Unauthenticated.'], 401);
    }
}
