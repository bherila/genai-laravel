<?php

namespace Bherila\GenAiLaravel\Exceptions;

/**
 * Thrown when a provider returns a rate-limit (429) response.
 * retryAfter carries the server-suggested delay in seconds (from Retry-After header), or null if absent.
 */
class GenAiRateLimitException extends GenAiException
{
    public function __construct(string $message, public readonly ?int $retryAfter = null)
    {
        parent::__construct($message);
    }
}
