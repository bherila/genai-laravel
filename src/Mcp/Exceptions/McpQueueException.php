<?php

namespace Bherila\GenAiLaravel\Mcp\Exceptions;

use RuntimeException;

final class McpQueueException extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(string $message, public readonly int $httpStatus = 409, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}
