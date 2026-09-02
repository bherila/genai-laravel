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
 * Secrets never mix. When a credentials object is supplied it is authoritative
 * for every secret it carries — API key, bearer token, session token — and an
 * empty one is an error rather than a silent fall back to the deployment's
 * credentials. Only non-secret settings (model, region, timeout, max tokens,
 * response MIME type) inherit from `genai.providers.*` when left null.
 *
 * That boundary matters beyond tidiness: Anthropic Files objects are scoped to
 * the API key's workspace, so a tenant request that silently borrowed the
 * deployment key would upload that tenant's document into the shared workspace.
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
            'gemini' => self::makeGemini($credentials instanceof GeminiCredentials ? $credentials : null),
            'bedrock' => self::makeBedrock($credentials instanceof BedrockCredentials ? $credentials : null),
            'anthropic' => self::makeAnthropic($credentials instanceof AnthropicCredentials ? $credentials : null),
            default => throw new GenAiException("Unknown GenAI provider: {$provider}"),
        };
    }

    private static function makeGemini(?GeminiCredentials $credentials): GeminiClient
    {
        $cfg = config('genai.providers.gemini', []);
        $apiKey = self::secret('gemini', 'api_key', $credentials?->apiKey, $cfg['api_key'] ?? null);

        return new GeminiClient(
            apiKey: $apiKey,
            model: self::pick($credentials?->model, $cfg['model'] ?? null, 'gemini-3.6-flash'),
            timeout: (int) ($cfg['timeout'] ?? 240),
            responseMimeType: self::nullableStringConfig($cfg['response_mime_type'] ?? 'application/json'),
        );
    }

    private static function makeBedrock(?BedrockCredentials $credentials): BedrockClient
    {
        $cfg = config('genai.providers.bedrock', []);
        $apiKey = self::secret('bedrock', 'api_key', $credentials?->apiKey, $cfg['api_key'] ?? null);

        // A tenant's bearer token must never be paired with the deployment's STS
        // session token: they are halves of one credential.
        $sessionToken = $credentials !== null
            ? ($credentials->sessionToken ?? '')
            : (is_string($cfg['session_token'] ?? null) ? $cfg['session_token'] : '');

        return new BedrockClient(
            apiKey: $apiKey,
            modelId: self::pick($credentials?->model, $cfg['model'] ?? null, 'us.anthropic.claude-haiku-4-5-20251001-v1:0'),
            region: self::pick($credentials?->region, $cfg['region'] ?? null, 'us-east-1'),
            sessionToken: $sessionToken,
            timeout: (int) ($cfg['timeout'] ?? 240),
        );
    }

    private static function makeAnthropic(?AnthropicCredentials $credentials): AnthropicClient
    {
        $cfg = config('genai.providers.anthropic', []);
        $apiKey = self::secret('anthropic', 'api_key', $credentials?->apiKey, $cfg['api_key'] ?? null);

        return new AnthropicClient(
            apiKey: $apiKey,
            model: self::pick($credentials?->model, $cfg['model'] ?? null, 'claude-sonnet-4-6'),
            maxTokens: (int) ($cfg['max_tokens'] ?? 8192),
            timeout: (int) ($cfg['timeout'] ?? 240),
        );
    }

    /**
     * Resolve one secret. Supplied credentials are authoritative: when a
     * credentials object is present its value is used or the call fails, and
     * config is never consulted as a fallback.
     *
     * @throws GenAiException
     */
    private static function secret(string $provider, string $key, ?string $supplied, mixed $configured): string
    {
        if ($supplied !== null) {
            if ($supplied === '') {
                throw new GenAiException(sprintf(
                    'A non-empty %s is required when passing credentials to GenAiClientFactory::make(); '
                    .'refusing to fall back to genai.providers.%s.%s, which belongs to the deployment '
                    .'rather than to this caller.',
                    $key,
                    $provider,
                    $key,
                ));
            }

            return $supplied;
        }

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        throw new GenAiException("genai.providers.{$provider}.{$key} is not set.");
    }

    /**
     * Resolve one non-secret setting: supplied wins over config, config over the
     * package default. Only safe for values like model and region, never secrets.
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
