<?php

namespace Bherila\GenAiLaravel\Mcp\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $mailbox_id
 * @property list<string> $scopes
 */
final class McpToken extends Model
{
    use HasUuids;

    protected $table = 'genai_mcp_tokens';

    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['scopes' => 'array', 'expires_at' => 'immutable_datetime', 'last_used_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }
}
