<?php

namespace Bherila\GenAiLaravel\Mcp;

use Bherila\GenAiLaravel\Contracts\QueuedGenAiClient;
use Bherila\GenAiLaravel\Mcp\Models\McpMailbox;

final readonly class McpClient implements QueuedGenAiClient
{
    public function __construct(private McpQueueService $queue, private McpMailbox|string $mailbox) {}

    public static function forMailbox(McpMailbox|string $mailbox): self
    {
        return new self(app(McpQueueService::class), $mailbox);
    }

    public function provider(): string
    {
        return 'mcp';
    }

    public function enqueue(GenAiRequestPayload $payload, ?EnqueueOptions $options = null): PendingGenAiRequest
    {
        return new PendingGenAiRequest($this->queue->enqueue($this->mailbox, $payload, $options), $this->queue);
    }
}
