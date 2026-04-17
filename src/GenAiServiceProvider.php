<?php

namespace Bherila\GenAiLaravel;

use Bherila\GenAiLaravel\Clients\GenAiClientFactory;
use Bherila\GenAiLaravel\Contracts\GenAiClient;
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
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/genai.php' => config_path('genai.php'),
            ], 'genai-config');
        }
    }
}
