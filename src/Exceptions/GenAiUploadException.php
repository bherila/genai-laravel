<?php

namespace Bherila\GenAiLaravel\Exceptions;

/**
 * Thrown when a File API upload or delete fails.
 *
 * Before this existed, uploadFile() returned null both for "this provider has no
 * File API" and for "the upload failed", which callers could not tell apart. A
 * provider without a File API now throws GenAiUnsupportedOperationException, a
 * failed upload throws this, and null is no longer a return value.
 */
class GenAiUploadException extends GenAiFatalException
{
    /**
     * @param  int|null  $status  HTTP status returned by the provider, when the failure was a response.
     * @param  string  $body  Raw response body, for diagnostics.
     */
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly string $body = '',
    ) {
        parent::__construct($message);
    }
}
