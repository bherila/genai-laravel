<?php

namespace Bherila\GenAiLaravel\Contracts;

use Bherila\GenAiLaravel\Mcp\Models\McpDelivery;

interface CompletionDelivery
{
    /** Apply the completion idempotently, then return true to acknowledge it. */
    public function deliver(McpDelivery $delivery): bool;
}
