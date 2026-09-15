<?php

namespace Bherila\GenAiLaravel\Mcp\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property bool $enabled
 */
final class McpMailbox extends Model
{
    use HasUuids;

    protected $table = 'genai_mcp_mailboxes';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    /** @return HasMany<McpRequest, $this> */
    public function requests(): HasMany
    {
        return $this->hasMany(McpRequest::class, 'mailbox_id');
    }
}
