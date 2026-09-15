<?php

namespace Bherila\GenAiLaravel\Mcp\Events;

final readonly class McpRequestQueued
{
    public function __construct(public string $requestId) {}
}
