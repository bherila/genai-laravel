<?php

namespace Bherila\GenAiLaravel\Exceptions;

/**
 * Thrown when the provider rejected the *configured model id* — unknown,
 * retired, not enabled for this account, or not callable the way it was asked
 * for (a Bedrock base id that now requires an inference profile).
 *
 * This is configuration, not payload: every request with this model will fail
 * the same way until someone changes the setting, so retrying the job or
 * shrinking the prompt cannot help.
 *
 * Extends GenAiFatalException, so callers that only catch that keep working.
 */
class GenAiModelUnavailableException extends GenAiFatalException implements GenAiConfigurationException
{
    /**
     * @param  string  $message  The provider's own error text, prefixed with the request context.
     * @param  string|null  $provider  Provider slug (`anthropic`, `bedrock`, `gemini`).
     * @param  string|null  $modelId  Model id the client was configured with, when it knows it.
     */
    public function __construct(
        string $message,
        public readonly ?string $provider = null,
        public readonly ?string $modelId = null,
    ) {
        parent::__construct($message);
    }

    public function provider(): ?string
    {
        return $this->provider;
    }
}
