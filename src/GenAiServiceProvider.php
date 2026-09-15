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
use Bherila\GenAiLaravel\Mcp\Http\McpNoStore;
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

        $this->app->bind(MailboxAccessResolver::class, fn () => config('genai.mcp.personal_tokens.enabled', false)
            ? new PersonalTokenMailboxAccessResolver
            : new DenyAllMailboxAccessResolver);
        $this->app->bind(AttachmentResolver::class, StorageAttachmentResolver::class);
        $this->app->bind(CompletionDelivery::class, RejectingCompletionDelivery::class);
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
