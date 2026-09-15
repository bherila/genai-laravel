<?php

namespace Bherila\GenAiLaravel;

use Bherila\GenAiLaravel\Clients\GenAiClientFactory;
use Bherila\GenAiLaravel\Contracts\AttachmentResolver;
use Bherila\GenAiLaravel\Contracts\CompletionDelivery;
use Bherila\GenAiLaravel\Contracts\GenAiClient;
use Bherila\GenAiLaravel\Contracts\MailboxAccessResolver;
use Bherila\GenAiLaravel\Mcp\Attachments\StorageAttachmentResolver;
use Bherila\GenAiLaravel\Mcp\Auth\DenyAllMailboxAccessResolver;
use Bherila\GenAiLaravel\Mcp\Auth\McpAuthenticate;
use Bherila\GenAiLaravel\Mcp\Auth\PersonalTokenMailboxAccessResolver;
use Bherila\GenAiLaravel\Mcp\Commands\DeliverMcpCompletions;
use Bherila\GenAiLaravel\Mcp\Commands\PruneMcpRequests;
use Bherila\GenAiLaravel\Mcp\Delivery\RejectingCompletionDelivery;
use Bherila\GenAiLaravel\Mcp\ExecutionContext;
use Bherila\GenAiLaravel\Mcp\Http\McpNoStore;
use Bherila\McpLaravelBridge\Http\McpHttpPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class GenAiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/genai.php', 'genai');

        // Bind the default client so GenAiClient::class resolves via the factory.
        $this->app->bind(GenAiClient::class, fn () => GenAiClientFactory::make());

        // Named bindings for explicit provider selection in DI.
        $this->app->bind('genai.gemini', fn () => GenAiClientFactory::make('gemini'));
        $this->app->bind('genai.bedrock', fn () => GenAiClientFactory::make('bedrock'));
        $this->app->bind('genai.anthropic', fn () => GenAiClientFactory::make('anthropic'));

        if (! $this->app->bound(MailboxAccessResolver::class)) {
            $this->app->bind(MailboxAccessResolver::class, fn () => config('genai.mcp.personal_tokens.enabled', false)
                ? new PersonalTokenMailboxAccessResolver
                : new DenyAllMailboxAccessResolver);
        }
        if (! $this->app->bound(AttachmentResolver::class)) {
            $this->app->bind(AttachmentResolver::class, StorageAttachmentResolver::class);
        }
        if (! $this->app->bound(CompletionDelivery::class)) {
            $this->app->bind(CompletionDelivery::class, RejectingCompletionDelivery::class);
        }
        if (! $this->app->bound(McpHttpPolicy::class)) {
            $this->app->singleton(McpHttpPolicy::class, fn () => new McpHttpPolicy(
                allowedOrigins: fn (): array => array_values(config('genai.mcp.server.allowed_origins', [])),
                allowedHosts: fn (): array => array_values(config('genai.mcp.server.allowed_hosts', [])),
                maxRequestBodyBytes: (int) config('genai.mcp.server.max_body_bytes', 262144),
                maxResponseBodyBytes: (int) config('genai.mcp.server.max_response_body_bytes', 1048576),
            ));
        }
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/genai.php' => config_path('genai.php'),
            ], 'genai-config');
            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'genai-mcp-migrations');
            $this->commands([DeliverMcpCompletions::class, PruneMcpRequests::class]);
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        RateLimiter::for('genai-mcp', function (Request $request): Limit {
            $context = $request->attributes->get(ExecutionContext::class);
            $identity = $context instanceof ExecutionContext ? $context->principalKey : (string) $request->ip();

            return Limit::perMinute((int) config('genai.mcp.rest.requests_per_minute', 60))
                ->by('genai-mcp:'.hash('sha256', $identity));
        });
        $this->app['router']->aliasMiddleware('genai.mcp.auth', McpAuthenticate::class);
        $this->app['router']->aliasMiddleware('genai.mcp.no_store', McpNoStore::class);
        if ((bool) config('genai.mcp.enabled', false) && (bool) config('genai.mcp.rest.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/mcp.php');
        }
        if ((bool) config('genai.mcp.enabled', false) && (bool) config('genai.mcp.server.enabled', false)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/mcp-server.php');
        }
    }
}
