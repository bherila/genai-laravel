<?php

namespace Bherila\GenAiLaravel\Mcp;

use Bherila\GenAiLaravel\Mcp\Models\McpClaimReceipt;
use Illuminate\Support\Str;

final class LeaseTokenFactory
{
    public function random(): string
    {
        return 'lease_'.Str::random(64);
    }

    public function forReceipt(McpClaimReceipt $receipt): string
    {
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true) ?: $key;
        }
        if ($key === '') {
            throw new \LogicException('APP_KEY is required for idempotent MCP claims.');
        }
        $material = implode("\0", [$receipt->id, $receipt->request_id, $receipt->principal_key, $receipt->idempotency_key]);

        return 'lease_'.rtrim(strtr(base64_encode(hash_hmac('sha256', $material, $key, true)), '+/', '-_'), '=');
    }
}
