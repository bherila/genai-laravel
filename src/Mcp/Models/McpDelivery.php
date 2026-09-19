<?php

namespace Bherila\GenAiLaravel\Mcp\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property array<string, mixed> $payload
 * @property Carbon $available_at
 * @property Carbon|null $acknowledged_at
 * @property Carbon|null $leased_until
 * @property string|null $lease_owner
 * @property int $attempt_count
 * @property string|null $last_error
 */
final class McpDelivery extends Model
{
    use HasUuids;

    protected $table = 'genai_mcp_deliveries';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'available_at' => 'immutable_datetime', 'acknowledged_at' => 'immutable_datetime', 'leased_until' => 'immutable_datetime'];
    }
}
