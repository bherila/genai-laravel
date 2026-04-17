<?php

namespace Bherila\GenAiLaravel\Exceptions;

/**
 * Thrown when a provider returns a rate-limit (429) response.
 * Callers may retry after a delay.
 */
class GenAiRateLimitException extends GenAiException {}
