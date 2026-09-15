<?php

namespace Bherila\GenAiLaravel\Mcp\Delivery;

use Bherila\GenAiLaravel\Contracts\CompletionDelivery;
use Bherila\GenAiLaravel\Mcp\Models\McpDelivery;

final class RejectingCompletionDelivery implements CompletionDelivery
{
    public function deliver(McpDelivery $delivery): bool
    {
        return false;
    }
}
