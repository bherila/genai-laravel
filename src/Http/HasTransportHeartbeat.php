<?php

namespace Bherila\GenAiLaravel\Http;

use Bherila\GenAiLaravel\Exceptions\GenAiUnsupportedOperationException;
use Closure;
use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

trait HasTransportHeartbeat
{
    /** @var Closure():void|null */
    private ?Closure $transportHeartbeat = null;

    private ?\Throwable $heartbeatFailure = null;

    /** @param Closure():void $heartbeat */
    public function withTransportHeartbeat(Closure $heartbeat): static
    {
        if (! extension_loaded('curl')) {
            throw new GenAiUnsupportedOperationException('Transport heartbeats require the cURL HTTP handler.');
        }
        $clone = clone $this;
        $clone->transportHeartbeat = $heartbeat;

        return $clone;
    }

    /** @param callable():Response $send */
    private function heartbeatSend(callable $send): Response
    {
        $this->heartbeatFailure = null;
        try {
            return $send();
        } catch (\Throwable $e) {
            throw $this->heartbeatFailure ?? $e;
        } finally {
            $this->heartbeatFailure = null;
        }
    }

    private function heartbeatHttp(PendingRequest $http): PendingRequest
    {
        $http = clone $http;
        if ($this->transportHeartbeat !== null) {
            $factory = new HeartbeatCurlFactory(function (): int {
                try {
                    ($this->transportHeartbeat)();

                    return 0;
                } catch (\Throwable $e) {
                    $this->heartbeatFailure = $e;

                    return 1;
                }
            });
            $http->setHandler(new CurlHandler(['handle_factory' => $factory]));
        }

        return $http;
    }
}
