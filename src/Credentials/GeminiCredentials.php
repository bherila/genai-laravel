<?php

namespace Bherila\GenAiLaravel\Credentials;

/**
 * Per-request Google Gemini credentials.
 *
 * Everything not supplied here (timeout, response MIME type) still comes from
 * `genai.providers.gemini`.
 */
final class GeminiCredentials implements ProviderCredentials
{
    /**
     * @param  string  $apiKey  Gemini API key for this caller.
     * @param  string|null  $model  Model override; null keeps the configured model.
     */
    public function __construct(
        public readonly string $apiKey,
        public readonly ?string $model = null,
    ) {}

    public function provider(): string
    {
        return 'gemini';
    }
}
