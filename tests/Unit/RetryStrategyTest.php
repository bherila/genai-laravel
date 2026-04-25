<?php

namespace Bherila\GenAiLaravel\Tests\Unit;

use Bherila\GenAiLaravel\Exceptions\GenAiException;
use Bherila\GenAiLaravel\Exceptions\GenAiFatalException;
use Bherila\GenAiLaravel\Exceptions\GenAiRateLimitException;
use Bherila\GenAiLaravel\Http\RetryStrategy;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

class RetryStrategyTest extends TestCase
{
    /** @var list<int> */
    private array $sleeps = [];

    /**
     * Build a strategy whose sleeper records ms into $this->sleeps so tests
     * can assert delays without actually pausing the suite.
     */
    private function strategy(int $maxAttempts = 3, int $baseMs = 1000, int $maxMs = 30_000): RetryStrategy
    {
        $this->sleeps = [];

        return new RetryStrategy(
            maxAttempts: $maxAttempts,
            backoffBaseMs: $baseMs,
            backoffMaxMs: $maxMs,
            sleeper: function (int $ms) {
                $this->sleeps[] = $ms;
            },
        );
    }

    private function send(string $url): callable
    {
        return fn () => Http::get($url);
    }

    public function test_returns_immediately_on_success(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);
        $strategy = $this->strategy();

        $response = $strategy->execute($this->send('https://x.test/ok'), 'X');

        $this->assertInstanceOf(Response::class, $response);
        $this->assertTrue($response->successful());
        $this->assertSame([], $this->sleeps, 'No sleep should occur on a successful first request.');
    }

    public function test_429_with_retry_after_honors_header(): void
    {
        $callCount = 0;
        Http::fake([
            'https://x.test/*' => function () use (&$callCount) {
                $callCount++;

                return $callCount === 1
                    ? Http::response([], 429, ['Retry-After' => '7'])
                    : Http::response(['ok' => true]);
            },
        ]);

        $strategy = $this->strategy();

        $response = $strategy->execute($this->send('https://x.test/r'), 'X');

        $this->assertTrue($response->successful());
        $this->assertSame([7000], $this->sleeps);
        $this->assertSame(2, $callCount);
    }

    public function test_429_without_retry_after_uses_exponential_backoff(): void
    {
        $callCount = 0;
        Http::fake([
            'https://x.test/*' => function () use (&$callCount) {
                $callCount++;

                return $callCount < 3
                    ? Http::response([], 429)
                    : Http::response(['ok' => true]);
            },
        ]);

        $strategy = $this->strategy(maxAttempts: 3, baseMs: 100);

        $strategy->execute($this->send('https://x.test/r'), 'X');

        $this->assertSame([100, 200], $this->sleeps, 'First retry waits base*2^0; second waits base*2^1.');
        $this->assertSame(3, $callCount);
    }

    public function test_503_is_retried_with_backoff(): void
    {
        $callCount = 0;
        Http::fake([
            'https://x.test/*' => function () use (&$callCount) {
                $callCount++;

                return $callCount === 1
                    ? Http::response([], 503)
                    : Http::response(['ok' => true]);
            },
        ]);

        $strategy = $this->strategy(maxAttempts: 3, baseMs: 50);

        $strategy->execute($this->send('https://x.test/r'), 'X');

        $this->assertSame([50], $this->sleeps);
        $this->assertSame(2, $callCount);
    }

    public function test_400_is_not_retried_and_throws_fatal(): void
    {
        Http::fake(['*' => Http::response(['error' => 'bad'], 400)]);
        $strategy = $this->strategy();

        $this->expectException(GenAiFatalException::class);
        try {
            $strategy->execute($this->send('https://x.test/r'), 'X');
        } finally {
            $this->assertSame([], $this->sleeps, '4xx must not trigger a retry.');
        }
    }

    public function test_401_is_not_retried_and_throws_fatal(): void
    {
        Http::fake(['*' => Http::response([], 401)]);
        $strategy = $this->strategy();

        $this->expectException(GenAiFatalException::class);
        $strategy->execute($this->send('https://x.test/r'), 'X');
    }

    public function test_500_falls_through_to_generic_genai_exception(): void
    {
        // 500 isn't in the retryable list (only 502/503/504 are) — must throw GenAiException.
        Http::fake(['*' => Http::response([], 500)]);
        $strategy = $this->strategy();

        $this->expectException(GenAiException::class);
        try {
            $strategy->execute($this->send('https://x.test/r'), 'X');
        } finally {
            $this->assertSame([], $this->sleeps);
        }
    }

    public function test_exhausted_429_throws_with_retry_after_populated(): void
    {
        $callCount = 0;
        Http::fake([
            'https://x.test/*' => function () use (&$callCount) {
                $callCount++;
                $header = $callCount < 3 ? '0' : '99';

                return Http::response([], 429, ['Retry-After' => $header]);
            },
        ]);

        $strategy = $this->strategy(maxAttempts: 3, baseMs: 1);

        try {
            $strategy->execute($this->send('https://x.test/r'), 'X');
            $this->fail('Expected GenAiRateLimitException');
        } catch (GenAiRateLimitException $e) {
            $this->assertSame(99, $e->retryAfter, 'retryAfter reflects the last server-suggested delay.');
            $this->assertSame(3, $callCount);
        }
    }

    public function test_max_attempts_one_disables_retry(): void
    {
        $callCount = 0;
        Http::fake([
            'https://x.test/*' => function () use (&$callCount) {
                $callCount++;

                return Http::response([], 429, ['Retry-After' => '0']);
            },
        ]);

        $strategy = $this->strategy(maxAttempts: 1);

        $this->expectException(GenAiRateLimitException::class);
        try {
            $strategy->execute($this->send('https://x.test/r'), 'X');
        } finally {
            $this->assertSame(1, $callCount, 'No retry should happen when max_attempts=1.');
            $this->assertSame([], $this->sleeps);
        }
    }

    public function test_backoff_capped_at_max_ms(): void
    {
        $callCount = 0;
        Http::fake([
            'https://x.test/*' => function () use (&$callCount) {
                $callCount++;

                return $callCount < 3
                    ? Http::response([], 503)
                    : Http::response(['ok' => true]);
            },
        ]);

        $strategy = $this->strategy(maxAttempts: 3, baseMs: 10_000, maxMs: 12_000);

        $strategy->execute($this->send('https://x.test/r'), 'X');

        // Without the cap: 10_000 then 20_000. With cap: 10_000 then 12_000.
        $this->assertSame([10_000, 12_000], $this->sleeps);
    }

    public function test_retry_after_header_capped_at_max_ms(): void
    {
        $callCount = 0;
        Http::fake([
            'https://x.test/*' => function () use (&$callCount) {
                $callCount++;

                return $callCount === 1
                    ? Http::response([], 429, ['Retry-After' => '600']) // 600s = 600_000ms
                    : Http::response(['ok' => true]);
            },
        ]);

        $strategy = $this->strategy(maxAttempts: 2, maxMs: 5_000);

        $strategy->execute($this->send('https://x.test/r'), 'X');

        $this->assertSame([5_000], $this->sleeps, 'Retry-After must respect backoff_max_ms cap.');
    }

    public function test_from_config_reads_genai_retry(): void
    {
        config([
            'genai.retry' => [
                'max_attempts' => 7,
                'backoff_base_ms' => 250,
                'backoff_max_ms' => 9999,
            ],
        ]);

        $strategy = RetryStrategy::fromConfig();

        $this->assertSame(7, $strategy->maxAttempts);
        $this->assertSame(250, $strategy->backoffBaseMs);
        $this->assertSame(9999, $strategy->backoffMaxMs);
    }
}
