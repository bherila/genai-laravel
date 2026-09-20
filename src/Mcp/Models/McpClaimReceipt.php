<?php

namespace Bherila\GenAiLaravel\Mcp\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $request_id
 * @property string $mailbox_id
 * @property string $principal_key
 * @property string $idempotency_key
 * @property string|null $queue_filter
 * @property Carbon $expires_at
 */
final class McpClaimReceipt extends Model
{
    use HasUuids;

    protected $table = 'genai_mcp_claim_receipts';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime'];
    }
}
