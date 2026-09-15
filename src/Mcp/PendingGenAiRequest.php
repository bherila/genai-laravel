<?php

namespace Bherila\GenAiLaravel\Mcp;

use Bherila\GenAiLaravel\GenAiResponse;
use Bherila\GenAiLaravel\Mcp\Enums\McpRequestStatus;
use Bherila\GenAiLaravel\Mcp\Models\McpRequest;
use Bherila\GenAiLaravel\Usage;

final class PendingGenAiRequest
{
    public readonly string $id;

    public function __construct(private McpRequest $request, private readonly McpQueueService $queue)
    {
        $this->id = $request->id;
    }

    public function status(): McpRequestStatus
    {
        return $this->refresh()->status;
    }

    public function response(): ?GenAiResponse
    {
        $request = $this->refresh();
        if ($request->status !== McpRequestStatus::Completed) {
            return null;
        }
        $result = $request->result;

        return new GenAiResponse(
            text: (string) ($result['text'] ?? ''),
            toolCalls: array_map(static fn (array $call): array => ['id' => '', 'name' => $call['name'], 'input' => $call['input']], $result['tool_calls'] ?? []),
            usage: Usage::empty(),
            raw: ['provider' => 'mcp', 'executor' => $result['executor'] ?? [], 'receipt_id' => $request->completion_receipt_id],
        );
    }

    public function cancel(): void
    {
        $this->request = $this->queue->cancel($this->request);
    }

    private function refresh(): McpRequest
    {
        return $this->request = $this->request->fresh() ?? $this->request;
    }
}
