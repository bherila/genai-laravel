<?php

namespace Bherila\GenAiLaravel\Clients;

use Bherila\GenAiLaravel\Contracts\GenAiClient;
use Bherila\GenAiLaravel\Credentials\AnthropicCredentials;
use Bherila\GenAiLaravel\Credentials\BedrockCredentials;
use Bherila\GenAiLaravel\Credentials\GeminiCredentials;
use Bherila\GenAiLaravel\Credentials\ProviderCredentials;
use Bherila\GenAiLaravel\Exceptions\GenAiException;

/**
 * Resolves a GenAiClient implementation by provider name from config.
 *
 * Usage:
 *   $client = GenAiClientFactory::make();           // uses genai.default
 *   $client = GenAiClientFactory::make('gemini');
 *   $client = GenAiClientFactory::make('bedrock');
 *
 * Credentials can also be supplied per call, for keys that belong to a tenant or
 * a user rather than to the deployment. The provider is inferred from the
 * credential type, so the name is optional:
 *
 *   $client = GenAiClientFactory::make(
 *       credentials: new GeminiCredentials(apiKey: $user->gemini_key),
 *   );
 *
 * Anything the credentials leave null (timeout, max tokens, response MIME type,
 * and the model unless overridden) still comes from `genai.providers.*`.
 */
class GenAiClientFactory
{
    /**
     * @param  string|null  $provider  Provider name; defaults to the credentials' provider, then genai.default.
     * @param  ProviderCredentials|null  $credentials  Per-request credentials; null reads them from config.
     *
     * @throws GenAiException When the provider is unknown, misconfigured, or does not match the credentials.
     */
    public static function make(?string $provider = null, ?ProviderCredentials $credentials = null): GenAiClient
    {
        $provider ??= $credentials?->provider() ?? config('genai.default', 'gemini');

        if ($credentials !== null && $credentials->provider() !== $provider) {
            throw new GenAiException(sprintf(
                'Provider "%s" was requested with %s, which is for "%s".',
                $provider,
                $credentials::class,
                $credentials->provider(),
            ));
        }

        return match ($provider) {
            'gemini' => static::makeGemini($credentials instanceof GeminiCredentials ? $credentials : null),
            'bedrock' => static::makeBedrock($credentials instanceof BedrockCredentials ? $credentials : null),
            'anthropic' => static::makeAnthropic($credentials instanceof AnthropicCredentials ? $credentials : null),
            default => throw new GenAiException("Unknown GenAI provider: {$provider}"),
        };
    }

    private static function makeGemini(?GeminiCredentials $credentials): GeminiClient
    {
        $cfg = config('genai.providers.gemini', []);
        $apiKey = static::pick($credentials?->apiKey, $cfg['api_key'] ?? null, '');

        if ($apiKey === '') {
            throw new GenAiException('genai.providers.gemini.api_key is not set.');
        }

        return new GeminiClient(
            apiKey: $apiKey,
            model: static::pick($credentials?->model, $cfg['model'] ?? null, 'gemini-3.6-flash'),
            timeout: (int) ($cfg['timeout'] ?? 240),
            responseMimeType: static::nullableStringConfig($cfg['response_mime_type'] ?? 'application/json'),
        );
    }

    private static function makeBedrock(?BedrockCredentials $credentials): BedrockClient
    {
        $cfg = config('genai.providers.bedrock', []);
        $apiKey = static::pick($credentials?->apiKey, $cfg['api_key'] ?? null, '');

        if ($apiKey === '') {
            throw new GenAiException('genai.providers.bedrock.api_key is not set.');
        }

        return new BedrockClient(
            apiKey: $apiKey,
            modelId: static::pick($credentials?->model, $cfg['model'] ?? null, 'us.anthropic.claude-haiku-4-5-20251001-v1:0'),
            region: static::pick($credentials?->region, $cfg['region'] ?? null, 'us-east-1'),
            sessionToken: static::pick($credentials?->sessionToken, $cfg['session_token'] ?? null, ''),
            timeout: (int) ($cfg['timeout'] ?? 240),
        );
    }

    private static function makeAnthropic(?AnthropicCredentials $credentials): AnthropicClient
    {
        $cfg = config('genai.providers.anthropic', []);
        $apiKey = static::pick($credentials?->apiKey, $cfg['api_key'] ?? null, '');

        if ($apiKey === '') {
            throw new GenAiException('genai.providers.anthropic.api_key is not set.');
        }

        return new AnthropicClient(
            apiKey: $apiKey,
            model: static::pick($credentials?->model, $cfg['model'] ?? null, 'claude-sonnet-4-6'),
            maxTokens: (int) ($cfg['max_tokens'] ?? 8192),
            timeout: (int) ($cfg['timeout'] ?? 240),
        );
    }

    /**
     * Supplied credentials win over config, and config over the package default.
     */
    private static function pick(?string $supplied, mixed $configured, string $default): string
    {
        if ($supplied !== null && $supplied !== '') {
            return $supplied;
        }

        return is_string($configured) && $configured !== '' ? $configured : $default;
    }

    private static function nullableStringConfig(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
