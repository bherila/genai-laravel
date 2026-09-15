<?php

namespace Bherila\GenAiLaravel\Mcp\Events;

final readonly class McpRequestCompleted
{
    public function __construct(public string $requestId, public string $receiptId) {}
}
