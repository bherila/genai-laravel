<?php

namespace Bherila\GenAiLaravel\Mcp\Events;

final readonly class McpRequestExpired
{
    public function __construct(public string $requestId) {}
}
