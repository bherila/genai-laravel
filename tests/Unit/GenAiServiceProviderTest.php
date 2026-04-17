<?php

namespace Bherila\GenAiLaravel\Tests\Unit;

use Bherila\GenAiLaravel\Clients\GeminiClient;
use Bherila\GenAiLaravel\Contracts\GenAiClient;
use Bherila\GenAiLaravel\Facades\GenAi;
use Bherila\GenAiLaravel\GenAiServiceProvider;
use Orchestra\Testbench\TestCase;

class GenAiServiceProviderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [GenAiServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['GenAi' => GenAi::class];
    }

    public function test_config_is_merged_with_defaults(): void
    {
        $this->assertNotNull(config('genai.default'));
        $this->assertNotNull(config('genai.providers.gemini'));
        $this->assertNotNull(config('genai.providers.bedrock'));
    }

    public function test_config_default_is_gemini(): void
    {
        $this->assertSame('gemini', config('genai.default'));
    }

    public function test_genai_client_interface_is_bound(): void
    {
        config(['genai.providers.gemini.api_key' => 'test-key']);
        $client = $this->app->make(GenAiClient::class);
        $this->assertInstanceOf(GenAiClient::class, $client);
    }

    public function test_named_gemini_binding_resolves(): void
    {
        config(['genai.providers.gemini.api_key' => 'test-key']);
        $client = $this->app->make('genai.gemini');
        $this->assertInstanceOf(GeminiClient::class, $client);
    }

    public function test_facade_resolves_to_client(): void
    {
        config(['genai.providers.gemini.api_key' => 'test-key']);
        $this->assertSame('gemini', GenAi::provider());
    }
}
