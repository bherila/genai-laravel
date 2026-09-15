<?php

namespace Bherila\GenAiLaravel\Mcp\Events;

final readonly class McpRequestFailed
{
    public function __construct(public string $requestId, public bool $terminal) {}
}
