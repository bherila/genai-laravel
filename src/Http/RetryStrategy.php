<?php

namespace Bherila\GenAiLaravel\Http;

use Bherila\GenAiLaravel\Exceptions\GenAiException;
use Bherila\GenAiLaravel\Exceptions\GenAiFatalException;
use Bherila\GenAiLaravel\Exceptions\GenAiRateLimitException;
use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

/**
 * Single source of truth for HTTP retry behaviour across all GenAI clients.
 *
 * Retries 429 (honoring `Retry-After: <seconds>` when present) and transient
 * 5xx (502/503/504); does not retry 400/401/403/404. After max_attempts the
 * appropriate `GenAi*Exception` is thrown — `GenAiRateLimitException` carries
 * the last server-suggested `retryAfter` so callers can still queue work.
 *
 * `max_attempts` counts the initial request: `max_attempts = 1` disables retry.
 */
class RetryStrategy
{
    /** Statuses that should be retried until the budget is exhausted. */
    private const RETRYABLE_STATUSES = [429, 502, 503, 504];

    /** Statuses that should never be retried — they will not change on retry. */
    private const FATAL_STATUSES = [400, 401, 403, 404];

    /**
     * @param  Closure(int):void|null  $sleeper  Override `usleep()` for tests.
     */
    public function __construct(
        public readonly int $maxAttempts = 3,
        public readonly int $backoffBaseMs = 1000,
        public readonly int $backoffMaxMs = 30_000,
        private readonly ?Closure $sleeper = null,
    ) {}

    /**
     * Build a strategy from `config('genai.retry')`, falling back to defaults.
     */
    public static function fromConfig(): self
    {
        $cfg = function_exists('config') ? (array) config('genai.retry', []) : [];

        return new self(
            maxAttempts: (int) ($cfg['max_attempts'] ?? 3),
            backoffBaseMs: (int) ($cfg['backoff_base_ms'] ?? 1000),
            backoffMaxMs: (int) ($cfg['backoff_max_ms'] ?? 30_000),
        );
    }

    /**
     * Run `$send` with retry. Throws on final failure.
     *
     * @param  callable():Response  $send
     * @param  string  $errorContext  Used in log lines and exception messages
     *                                (e.g. "Anthropic API", "Bedrock list models").
     */
    public function execute(callable $send, string $errorContext): Response
    {
        $attempt = 0;
        while (true) {
            $response = $send();
            if ($response->successful()) {
                return $response;
            }

            $status = $response->status();
            $canRetry = $attempt < $this->maxAttempts - 1
                && in_array($status, self::RETRYABLE_STATUSES, true);

            if (! $canRetry) {
                $this->throwFor($response, $errorContext);
            }

            $this->sleep($this->delayMsFor($response, $attempt));
            $attempt++;
        }
    }

    /**
     * Delay before the next retry, in ms.
     *
     * Honors `Retry-After: <seconds>` on 429; otherwise exponential backoff
     * (`backoffBaseMs * 2^attempt`). Always capped at `backoffMaxMs`.
     */
    private function delayMsFor(Response $response, int $attempt): int
    {
        if ($response->status() === 429) {
            $header = $response->header('Retry-After');
            if ($header !== '') {
                return min(((int) $header) * 1000, $this->backoffMaxMs);
            }
        }

        $base = $this->backoffBaseMs * (1 << $attempt);

        return min($base, $this->backoffMaxMs);
    }

    private function sleep(int $ms): void
    {
        if ($ms <= 0) {
            return;
        }
        if ($this->sleeper !== null) {
            ($this->sleeper)($ms);

            return;
        }
        usleep($ms * 1000);
    }

    private function throwFor(Response $response, string $errorContext): never
    {
        $status = $response->status();
        $body = $response->body();

        Log::error("{$errorContext} request failed", [
            'status' => $status,
            'body' => $body,
        ]);

        if ($status === 429) {
            $header = $response->header('Retry-After');
            $retryAfter = $header !== '' ? (int) $header : null;
            throw new GenAiRateLimitException("{$errorContext} rate limit exceeded.", $retryAfter);
        }
        if (in_array($status, self::FATAL_STATUSES, true)) {
            throw new GenAiFatalException("{$errorContext} error: {$body}");
        }
        throw new GenAiException("{$errorContext} error {$status}: {$body}");
    }
}
