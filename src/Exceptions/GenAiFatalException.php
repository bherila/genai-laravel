<?php

namespace Bherila\GenAiLaravel\Exceptions;

/**
 * Thrown when a provider returns a non-retryable error (400 Bad Request, etc.).
 * Callers should mark the job as permanently failed.
 */
class GenAiFatalException extends GenAiException {}
