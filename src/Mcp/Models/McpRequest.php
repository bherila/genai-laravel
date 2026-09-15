<?php

namespace Bherila\GenAiLaravel\Mcp\Models;

use Bherila\GenAiLaravel\Mcp\Enums\McpRequestStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $mailbox_id
 * @property string $queue
 * @property McpRequestStatus $status
 * @property int $priority
 * @property array<string, mixed> $payload
 * @property array<string, mixed>|null $metadata
 * @property int $attempt_count
 * @property int $max_attempts
 * @property string|null $enqueue_hash
 * @property Carbon $available_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $leased_at
 * @property Carbon|null $lease_expires_at
 * @property string|null $lease_token_hash
 * @property string|null $lease_principal
 * @property Carbon|null $completed_at
 * @property Carbon|null $failed_at
 * @property array<string, mixed>|null $result
 * @property array<string, mixed>|null $error
 * @property string|null $completion_hash
 * @property string|null $completion_lease_hash
 * @property string|null $completion_principal
 * @property string|null $completion_receipt_id
 * @property-read McpMailbox $mailbox
 * @property-read Collection<int, McpAttachment> $attachments
 * @property-read Collection<int, McpDelivery> $deliveries
 */
final class McpRequest extends Model
{
    use HasUuids;

    protected $table = 'genai_mcp_requests';

    protected $guarded = [];

    protected $hidden = ['lease_token_hash', 'completion_lease_hash'];

    protected function casts(): array
    {
        return [
            'status' => McpRequestStatus::class,
            'payload' => 'array', 'metadata' => 'array', 'result' => 'array', 'error' => 'array',
            'available_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime',
            'leased_at' => 'immutable_datetime', 'lease_expires_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime', 'failed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<McpMailbox, $this> */
    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(McpMailbox::class, 'mailbox_id');
    }

    /** @return HasMany<McpAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(McpAttachment::class, 'request_id');
    }

    /** @return HasMany<McpDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(McpDelivery::class, 'request_id');
    }
}
