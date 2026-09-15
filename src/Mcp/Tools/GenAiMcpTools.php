<?php

namespace Bherila\GenAiLaravel\Mcp\Tools;

use Bherila\GenAiLaravel\Mcp\Exceptions\McpQueueException;
use Bherila\GenAiLaravel\Mcp\ExecutionContext;
use Bherila\GenAiLaravel\Mcp\McpQueueService;
use Illuminate\Http\Request;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

final readonly class GenAiMcpTools
{
    public function __construct(private McpQueueService $queue) {}

    /** @return array<string, mixed> */
    public function queueStatus(#[Schema(maxLength: 80)] ?string $queue = null): array
    {
        return $this->call(fn (): array => ['counts' => $this->queue->status($this->context(), $queue)]);
    }

    /** @return array<string, mixed> */
    public function claim(
        #[Schema(maxLength: 80)] ?string $queue = null,
        #[Schema(maxLength: 191)] ?string $idempotency_key = null,
    ): array {
        return $this->call(fn (): array => $this->queue->claim($this->context(), $queue, $idempotency_key) ?? ['empty' => true]);
    }

    /** @return array<string, mixed> */
    public function renew(
        #[Schema(format: 'uuid')] string $request_id,
        #[Schema(minLength: 32, maxLength: 255)] string $lease_token,
    ): array {
        return $this->call(fn (): array => $this->queue->renew($this->context(), $request_id, $lease_token));
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $executor
     * @return array<string, mixed>
     */
    public function complete(
        #[Schema(format: 'uuid')] string $request_id,
        #[Schema(minLength: 32, maxLength: 255)] string $lease_token,
        #[Schema(type: 'object')] array $response,
        #[Schema(type: 'object')] array $executor = [],
    ): array {
        return $this->call(fn (): array => $this->queue->complete($this->context(), $request_id, $lease_token, $response, $executor));
    }

    /** @return array<string, mixed> */
    public function fail(
        #[Schema(format: 'uuid')] string $request_id,
        #[Schema(minLength: 32, maxLength: 255)] string $lease_token,
        #[Schema(maxLength: 80)] string $error_code,
        #[Schema(maxLength: 1000)] string $error_message,
        bool $retryable = false,
    ): array {
        return $this->call(fn (): array => $this->queue->fail($this->context(), $request_id, $lease_token, $error_code, $error_message, $retryable));
    }

    private function context(): ExecutionContext
    {
        /** @var Request $request */
        $request = request();

        return $request->attributes->get(ExecutionContext::class)
            ?? throw new \RuntimeException('No authenticated GenAI execution context is available.');
    }

    /**
     * @param  callable(): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    private function call(callable $callback): array
    {
        try {
            return $callback();
        } catch (McpQueueException $exception) {
            throw new ToolCallException($exception->getMessage(), previous: $exception);
        }
    }
}
