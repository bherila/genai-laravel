<?php

namespace Bherila\GenAiLaravel\Contracts;

use Bherila\GenAiLaravel\Mcp\EnqueueOptions;
use Bherila\GenAiLaravel\Mcp\GenAiRequestPayload;
use Bherila\GenAiLaravel\Mcp\PendingGenAiRequest;

interface QueuedGenAiClient
{
    public function provider(): string;

    public function enqueue(GenAiRequestPayload $payload, ?EnqueueOptions $options = null): PendingGenAiRequest;
}
