<?php

namespace Bherila\GenAiLaravel\Credentials;

/**
 * Per-request Anthropic credentials.
 *
 * Everything not supplied here (max tokens, timeout) still comes from
 * `genai.providers.anthropic`.
 */
final class AnthropicCredentials implements ProviderCredentials
{
    /**
     * @param  string  $apiKey  Anthropic API key for this caller.
     * @param  string|null  $model  Model override; null keeps the configured model.
     */
    public function __construct(
        public readonly string $apiKey,
        public readonly ?string $model = null,
    ) {}

    public function provider(): string
    {
        return 'anthropic';
    }
}
