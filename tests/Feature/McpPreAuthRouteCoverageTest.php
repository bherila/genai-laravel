<?php

namespace Bherila\GenAiLaravel\Tests\Feature;

use Bherila\GenAiLaravel\GenAiServiceProvider;
use Bherila\GenAiLaravel\Mcp\Http\McpPreAuthGuard;
use Orchestra\Testbench\TestCase;

/**
 * The pre-auth guard recognizes package endpoints by route name, so every
 * package route must carry one of its names, and any prefix the router
 * accepts, including none, must be guarded.
 */
final class McpPreAuthRouteCoverageTest extends TestCase
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
        $app['config']->set('genai.mcp.rest.prefix', '');
        $app['config']->set('genai.mcp.rest.preauth_requests_per_minute', 1);
        $app['config']->set('genai.mcp.rest.max_body_bytes', 1024);
    }

    public function test_every_package_route_is_named_for_the_guard_and_every_guard_name_exists(): void
    {
        $guarded = [...McpPreAuthGuard::REST_ROUTES, McpPreAuthGuard::SERVER_ROUTE];
        $package = [];
        foreach ($this->app['router']->getRoutes()->getRoutes() as $route) {
            if (str_starts_with($route->getActionName(), 'Bherila\\GenAiLaravel\\')) {
                $package[] = (string) $route->getName();
            }
        }
        sort($package);
        sort($guarded);

        $this->assertSame($guarded, $package);
    }

    public function test_a_root_mounted_rest_stack_is_still_limited_and_capped(): void
    {
        $this->postJson('/claims', ['queue' => str_repeat('x', 2048)])->assertStatus(413);
        $this->postJson('/claims', [])->assertStatus(429);
    }
}
