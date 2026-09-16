<?php

namespace Bherila\GenAiLaravel\Tests\Unit;

use Bherila\GenAiLaravel\Clients\AnthropicClient;
use Bherila\GenAiLaravel\GenAiRequest;
use Bherila\GenAiLaravel\Http\HasTransportHeartbeat;
use Bherila\GenAiLaravel\Http\RetryStrategy;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use RuntimeException;

class TransportHeartbeatTest extends TestCase
{
    public function test_generate_heartbeats_are_per_request_and_do_not_leak(): void
    {
        Http::fake(['*' => Http::response(['content' => [['type' => 'text', 'text' => 'ok']]])]);
        $client = new AnthropicClient('test');
        $beats = 0;
        $request = GenAiRequest::with($client)->prompt('hello');
        $request->generate(function () use (&$beats): void {
            $beats++;
        });
        $this->assertGreaterThanOrEqual(2, $beats);
        $before = $beats;
        $request->generate();
        $this->assertSame($before, $beats);
    }

    public function test_retry_wait_is_sliced_and_loss_prevents_next_attempt(): void
    {
        $sleeps = [];
        $strategy = new RetryStrategy(backoffBaseMs: 3500, sleeper: function (int $ms) use (&$sleeps): void {
            $sleeps[] = $ms;
        });
        $sends = 0;
        try {
            $strategy->execute(function () use (&$sends) {
                $sends++;

                return new \Illuminate\Http\Client\Response(new Response(503));
            }, 'test', function () use (&$sleeps): void {
                if (count($sleeps) === 2) {
                    throw new RuntimeException('lost');
                }
            });
            $this->fail('Lost ownership must stop retries.');
        } catch (RuntimeException $e) {
            $this->assertSame('lost', $e->getMessage());
        }
        $this->assertSame([1000, 1000], $sleeps);
        $this->assertSame(1, $sends);
    }

    public function test_blocking_stalled_transport_heartbeats_and_can_abort(): void
    {
        $process = proc_open([PHP_BINARY, __DIR__.'/../fixtures/stalled-http.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        try {
            $url = trim(fgets($pipes[1]));
            $sender = new class
            {
                use HasTransportHeartbeat;

                public function send(string $url): void
                {
                    $this->heartbeatSend(fn () => $this->heartbeatHttp(Http::timeout(10))->get($url));
                }
            };
            $beats = 0;
            $start = microtime(true);
            try {
                $sender->withTransportHeartbeat(function () use (&$beats, $start): void {
                    $beats++;
                    if (microtime(true) - $start > 1) {
                        throw new RuntimeException('lost during stall');
                    }
                })->send($url);
                $this->fail('Stalled transport must abort.');
            } catch (RuntimeException $e) {
                $this->assertSame('lost during stall', $e->getMessage());
            }
            $this->assertGreaterThan(2, $beats);
            $this->assertLessThan(3, microtime(true) - $start);
        } finally {
            proc_terminate($process);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
        }
    }
}
