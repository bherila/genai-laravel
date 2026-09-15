<?php

namespace Bherila\GenAiLaravel\Mcp\Events;

final readonly class McpRequestCancelled
{
    public function __construct(public string $requestId) {}
}
