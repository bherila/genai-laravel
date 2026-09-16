<?php

namespace Bherila\GenAiLaravel\Contracts;

use Closure;

interface HeartbeatAwareClient
{
    /** @param Closure():void $heartbeat */
    public function withTransportHeartbeat(Closure $heartbeat): static;
}
