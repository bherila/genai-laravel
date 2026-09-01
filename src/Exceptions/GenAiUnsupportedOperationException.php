<?php

namespace Bherila\GenAiLaravel\Exceptions;

/**
 * Thrown when a capability is not available on the selected provider — for
 * example a File API on a provider that only accepts inline bytes.
 *
 * This is a programming error rather than a provider failure, so it extends
 * GenAiFatalException (never retryable) and is distinct from GenAiUploadException,
 * which means the provider *does* support the operation and it went wrong.
 */
class GenAiUnsupportedOperationException extends GenAiFatalException {}
