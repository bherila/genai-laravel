<?php

namespace Bherila\GenAiLaravel\Mcp\Events;

final readonly class McpRequestClaimed
{
    public function __construct(public string $requestId) {}
}
