<?php

namespace Bherila\GenAiLaravel\Exceptions;

/**
 * Thrown before a request leaves the process when a file exceeds the provider's
 * documented size limit.
 *
 * Catching this separately lets callers downscale, split, or route the file to a
 * provider with a larger ceiling instead of burning a round trip on a rejection.
 */
class GenAiFileTooLargeException extends GenAiFatalException
{
    /**
     * @param  int  $actualBytes  Decoded size of the offending file.
     * @param  int  $limitBytes  Documented provider limit that was exceeded.
     */
    public function __construct(
        string $message,
        public readonly int $actualBytes = 0,
        public readonly int $limitBytes = 0,
    ) {
        parent::__construct($message);
    }
}
