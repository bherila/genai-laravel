<?php

namespace Bherila\GenAiLaravel\Exceptions;

/**
 * Thrown when the provider rejected the *credential*: missing, malformed,
 * revoked, expired, or not permitted to call the API at all.
 *
 * Distinct from GenAiModelUnavailableException because the remedy differs — a
 * key has to be reissued or a policy widened, rather than a model id changed.
 * A 403 that names a model as inaccessible is the model case, not this one.
 *
 * Extends GenAiFatalException, so callers that only catch that keep working.
 */
class GenAiAuthenticationException extends GenAiFatalException implements GenAiConfigurationException
{
    /**
     * @param  string  $message  The provider's own error text, prefixed with the request context.
     * @param  string|null  $provider  Provider slug (`anthropic`, `bedrock`, `gemini`).
     * @param  int|null  $status  HTTP status the provider answered with (401 or 403).
     */
    public function __construct(
        string $message,
        public readonly ?string $provider = null,
        public readonly ?int $status = null,
    ) {
        parent::__construct($message);
    }

    public function provider(): ?string
    {
        return $this->provider;
    }
}
