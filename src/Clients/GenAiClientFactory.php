<?php

namespace Bherila\GenAiLaravel\Clients;

use Bherila\GenAiLaravel\Contracts\GenAiClient;
use Bherila\GenAiLaravel\Exceptions\GenAiException;

/**
 * Resolves a GenAiClient implementation by provider name from config.
 *
 * Usage:
 *   $client = GenAiClientFactory::make();           // uses genai.default
 *   $client = GenAiClientFactory::make('gemini');
 *   $client = GenAiClientFactory::make('bedrock');
 */
class GenAiClientFactory
{
    /**
     * @throws GenAiException  When the provider is unknown or misconfigured.
     */
    public static function make(?string $provider = null): GenAiClient
    {
        $provider ??= config('genai.default', 'gemini');

        return match ($provider) {
            'gemini' => static::makeGemini(),
            'bedrock' => static::makeBedrock(),
            'anthropic' => static::makeAnthropic(),
            default => throw new GenAiException("Unknown GenAI provider: {$provider}"),
        };
    }

    private static function makeGemini(): GeminiClient
    {
        $cfg = config('genai.providers.gemini', []);
        $apiKey = $cfg['api_key'] ?? '';

        if ($apiKey === '') {
            throw new GenAiException('genai.providers.gemini.api_key is not set.');
        }

        return new GeminiClient(
            apiKey: $apiKey,
            model: $cfg['model'] ?? 'gemini-2.0-flash',
            timeout: (int) ($cfg['timeout'] ?? 240),
        );
    }

    private static function makeBedrock(): BedrockClient
    {
        $cfg = config('genai.providers.bedrock', []);
        $apiKey = $cfg['api_key'] ?? '';

        if ($apiKey === '') {
            throw new GenAiException('genai.providers.bedrock.api_key is not set.');
        }

        return new BedrockClient(
            apiKey: $apiKey,
            modelId: $cfg['model'] ?? 'us.anthropic.claude-haiku-4-20250514-v1:0',
            region: $cfg['region'] ?? 'us-east-1',
            sessionToken: $cfg['session_token'] ?? '',
        );
    }

    private static function makeAnthropic(): AnthropicClient
    {
        $cfg = config('genai.providers.anthropic', []);
        $apiKey = $cfg['api_key'] ?? '';

        if ($apiKey === '') {
            throw new GenAiException('genai.providers.anthropic.api_key is not set.');
        }

        return new AnthropicClient(
            apiKey: $apiKey,
            model: $cfg['model'] ?? 'claude-sonnet-4-6',
            maxTokens: (int) ($cfg['max_tokens'] ?? 8192),
            timeout: (int) ($cfg['timeout'] ?? 240),
        );
    }
}
