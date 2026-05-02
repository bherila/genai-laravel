<?php

namespace Bherila\GenAiLaravel\Tests\Unit;

use Bherila\GenAiLaravel\Clients\BedrockClient;
use Bherila\GenAiLaravel\Clients\GeminiClient;
use Bherila\GenAiLaravel\Clients\GenAiClientFactory;
use Bherila\GenAiLaravel\Exceptions\GenAiException;
use Bherila\GenAiLaravel\GenAiServiceProvider;
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
