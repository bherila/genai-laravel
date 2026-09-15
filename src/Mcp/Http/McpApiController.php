<?php

namespace Bherila\GenAiLaravel\Mcp\Http;

use Bherila\GenAiLaravel\Mcp\Exceptions\McpQueueException;
use Bherila\GenAiLaravel\Mcp\ExecutionContext;
use Bherila\GenAiLaravel\Mcp\McpQueueService;
use Bherila\McpLaravelBridge\Json;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class McpApiController
{
    public function __construct(private McpQueueService $queue) {}

    public function status(Request $request): JsonResponse
    {
        return $this->respond(fn (): array => ['counts' => $this->queue->status($this->context($request), $request->query('queue'))]);
    }

    public function show(Request $request, string $requestId): JsonResponse
    {
        return $this->respond(fn () => $this->queue->publicStatus($this->queue->findAuthorized($this->context($request), $requestId)));
    }

    public function claim(Request $request): Response
    {
        try {
            $this->assertKeys($request, ['queue']);
            $envelope = $this->queue->claim($this->context($request), $request->input('queue'), $request->header('Idempotency-Key'));

            return $envelope === null ? response('', 204) : response()->json($envelope);
        } catch (McpQueueException $e) {
            return response()->json(['message' => $e->getMessage(), 'details' => $e->details], $e->httpStatus);
        }
    }

    public function renew(Request $request, string $requestId): JsonResponse
    {
        return $this->respond(function () use ($request, $requestId): array {
            $this->assertKeys($request, ['lease_token']);
            $leaseToken = $request->input('lease_token');
            if (! is_string($leaseToken)) {
                throw new McpQueueException('lease_token must be a string.', 422);
            }

            return $this->queue->renew($this->context($request), $requestId, $leaseToken);
        });
    }

    public function complete(Request $request, string $requestId): JsonResponse
    {
        return $this->respond(function () use ($request, $requestId): array {
            $this->assertKeys($request, ['lease_token', 'response', 'executor']);
            $wire = Json::decodeObject($request->getContent());
            $response = $this->objectValue($wire['response'] ?? null);
            $executor = $this->objectValue($wire['executor'] ?? new \stdClass);
            if ($response === null || $executor === null) {
                throw new McpQueueException('response and executor must be JSON objects.', 422);
            }
            $leaseToken = $request->input('lease_token');
            if (! is_string($leaseToken)) {
                throw new McpQueueException('lease_token must be a string.', 422);
            }

            return $this->queue->complete(
                $this->context($request), $requestId, $leaseToken,
                $response,
                $executor,
            );
        });
    }

    public function fail(Request $request, string $requestId): JsonResponse
    {
        return $this->respond(function () use ($request, $requestId): array {
            $this->assertKeys($request, ['lease_token', 'error', 'retryable']);
            if (! is_array($request->input('error')) || ! is_bool($request->input('retryable'))) {
                throw new McpQueueException('error must be an object and retryable must be a boolean.', 422);
            }
            $leaseToken = $request->input('lease_token');
            if (! is_string($leaseToken)) {
                throw new McpQueueException('lease_token must be a string.', 422);
            }

            return $this->queue->fail(
                $this->context($request), $requestId, $leaseToken,
                (string) $request->input('error.code', 'executor_error'), (string) $request->input('error.message', 'Executor reported a failure.'),
                (bool) $request->boolean('retryable'),
            );
        });
    }

    private function context(Request $request): ExecutionContext
    {
        return $request->attributes->get(ExecutionContext::class);
    }

    /** @param list<string> $allowed */
    private function assertKeys(Request $request, array $allowed): void
    {
        $unknown = array_diff(array_keys($request->all()), $allowed);
        if ($unknown !== []) {
            throw new McpQueueException('Unknown request fields are not allowed.', 422, ['fields' => array_values($unknown)]);
        }
    }

    /** @param callable(): array<string, mixed> $callback */
    private function respond(callable $callback): JsonResponse
    {
        try {
            return response()->json($callback());
        } catch (McpQueueException $e) {
            return response()->json(['message' => $e->getMessage(), 'details' => $e->details], $e->httpStatus);
        }
    }

    /** @return array<string, mixed>|null */
    private function objectValue(mixed $value): ?array
    {
        if (is_object($value)) {
            return get_object_vars($value);
        }

        return is_array($value) && ! array_is_list($value) ? $value : null;
    }
}
