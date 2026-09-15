<?php

namespace Bherila\GenAiLaravel\Mcp;

use Bherila\GenAiLaravel\Mcp\Models\McpMailbox;

final readonly class McpClientFactory
{
    public function __construct(private McpQueueService $queue) {}

    public function forMailbox(McpMailbox|string $mailbox): McpClient
    {
        return new McpClient($this->queue, $mailbox);
    }
}
