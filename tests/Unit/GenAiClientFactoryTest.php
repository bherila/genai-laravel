<?php

namespace Bherila\GenAiLaravel\Tests\Unit;

use Bherila\GenAiLaravel\Clients\BedrockClient;
use Bherila\GenAiLaravel\Clients\GeminiClient;
use Bherila\GenAiLaravel\Clients\GenAiClientFactory;
use Bherila\GenAiLaravel\ContentBlock;
use Bherila\GenAiLaravel\Credentials\AnthropicCredentials;
use Bherila\GenAiLaravel\Credentials\BedrockCredentials;
use Bherila\GenAiLaravel\Credentials\GeminiCredentials;
use Bherila\GenAiLaravel\Exceptions\GenAiException;
use Bherila\GenAiLaravel\GenAiServiceProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

class GenAiClientFactoryTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [GenAiServiceProvider::class];
    }

    public function test_makes_gemini_client_when_default_is_gemini(): void
    {
        config(['genai.default' => 'gemini', 'genai.providers.gemini.api_key' => 'test-key']);
        $client = GenAiClientFactory::make();
        $this->assertInstanceOf(GeminiClient::class, $client);
        $this->assertSame('gemini', $client->provider());
    }

    public function test_gemini_factory_allows_response_mime_type_to_be_disabled(): void
    {
        config([
            'genai.default' => 'gemini',
            'genai.providers.gemini.api_key' => 'test-key',
            'genai.providers.gemini.response_mime_type' => '',
        ]);

        $client = GenAiClientFactory::make();

        $this->assertInstanceOf(GeminiClient::class, $client);
        $this->assertNull($this->geminiResponseMimeType($client));
    }

    public function test_makes_bedrock_client_when_default_is_bedrock(): void
    {
        config(['genai.default' => 'bedrock', 'genai.providers.bedrock.api_key' => 'test-key', 'genai.providers.bedrock.model' => 'model-id', 'genai.providers.bedrock.timeout' => 360]);
        $client = GenAiClientFactory::make();
        $this->assertInstanceOf(BedrockClient::class, $client);
        $this->assertSame('bedrock', $client->provider());
        $this->assertSame(360, $this->pendingRequestOptions($client)['timeout'] ?? null);
    }

    public function test_explicit_provider_overrides_default(): void
    {
        config(['genai.providers.gemini.api_key' => 'key']);
        $client = GenAiClientFactory::make('gemini');
        $this->assertInstanceOf(GeminiClient::class, $client);
    }

    public function test_throws_for_unknown_provider(): void
    {
        $this->expectException(GenAiException::class);
        $this->expectExceptionMessageMatches('/Unknown GenAI provider/');
        GenAiClientFactory::make('openai');
    }

    public function test_throws_when_gemini_api_key_missing(): void
    {
        config(['genai.providers.gemini.api_key' => null]);
        $this->expectException(GenAiException::class);
        $this->expectExceptionMessageMatches('/api_key is not set/');
        GenAiClientFactory::make('gemini');
    }

    public function test_throws_when_bedrock_api_key_missing(): void
    {
        config(['genai.providers.bedrock.api_key' => null]);
        $this->expectException(GenAiException::class);
        $this->expectExceptionMessageMatches('/api_key is not set/');
        GenAiClientFactory::make('bedrock');
    }

    // ── per-request credentials ──────────────────────────────────────────────

    public function test_credentials_override_the_configured_key(): void
    {
        config(['genai.default' => 'gemini', 'genai.providers.gemini.api_key' => 'site-wide-key']);

        Http::fake(['*' => Http::response(['candidates' => []])]);

        GenAiClientFactory::make(credentials: new GeminiCredentials(apiKey: 'per-user-key'))
            ->converse('', [['role' => 'user', 'content' => [ContentBlock::text('hi')]]]);

        Http::assertSent(fn (Request $req) => $req->header('x-goog-api-key')[0] === 'per-user-key');
    }

    public function test_credentials_alone_select_the_provider(): void
    {
        config(['genai.default' => 'gemini']);

        $client = GenAiClientFactory::make(credentials: new AnthropicCredentials(apiKey: 'k'));

        $this->assertSame('anthropic', $client->provider());
    }

    public function test_credentials_can_override_the_model(): void
    {
        config(['genai.providers.bedrock.api_key' => 'site', 'genai.providers.bedrock.model' => 'configured-model']);

        $client = GenAiClientFactory::make(credentials: new BedrockCredentials(
            apiKey: 'tenant-token',
            region: 'eu-west-1',
            model: 'eu.anthropic.claude-haiku-4-5-20251001-v1:0',
        ));

        $this->assertSame('eu.anthropic.claude-haiku-4-5-20251001-v1:0', $client->model());
    }

    public function test_credentials_leave_unset_values_to_config(): void
    {
        config([
            'genai.providers.gemini.api_key' => 'site',
            'genai.providers.gemini.model' => 'gemini-3.5-flash',
        ]);

        $client = GenAiClientFactory::make(credentials: new GeminiCredentials(apiKey: 'tenant'));

        $this->assertSame('gemini-3.5-flash', $client->model());
    }

    public function test_credentials_that_do_not_match_the_requested_provider_are_rejected(): void
    {
        config(['genai.providers.bedrock.api_key' => 'k']);

        $this->expectException(GenAiException::class);
        $this->expectExceptionMessageMatches('/which is for "gemini"/');

        GenAiClientFactory::make('bedrock', new GeminiCredentials(apiKey: 'k'));
    }

    public function test_credentials_work_without_any_configured_key(): void
    {
        config(['genai.providers.anthropic.api_key' => null]);

        $client = GenAiClientFactory::make(credentials: new AnthropicCredentials(apiKey: 'tenant-key'));

        $this->assertSame('anthropic', $client->provider());
    }

    /**
     * @return array<string, mixed>
     */
    private function pendingRequestOptions(BedrockClient $client): array
    {
        $clientReflection = new \ReflectionClass($client);
        $httpProperty = $clientReflection->getProperty('http');
        $httpProperty->setAccessible(true);
        $pendingRequest = $httpProperty->getValue($client);

        $requestReflection = new \ReflectionClass($pendingRequest);
        $optionsProperty = $requestReflection->getProperty('options');
        $optionsProperty->setAccessible(true);

        /** @var array<string, mixed> */
        return $optionsProperty->getValue($pendingRequest);
    }

    private function geminiResponseMimeType(GeminiClient $client): ?string
    {
        $clientReflection = new \ReflectionClass($client);
        $property = $clientReflection->getProperty('responseMimeType');
        $property->setAccessible(true);

        /** @var string|null */
        return $property->getValue($client);
    }
}
