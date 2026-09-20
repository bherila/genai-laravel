<?php

namespace Bherila\GenAiLaravel\Mcp\Models;

use Bherila\GenAiLaravel\Mcp\McpQueueService;
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

    protected static function booted(): void
    {
        // Removing a mailbox cascades its requests and attachments away, and
        // those rows are the only record of the objects this package wrote to
        // disk. Clear the owned bytes before the cascade, so the obvious call
        // is the safe one; a storage failure throws and the row survives.
        self::deleting(static function (self $mailbox): void {
            app(McpQueueService::class)->discardOwnedObjects($mailbox);
        });
    }

    /** @return HasMany<McpRequest, $this> */
    public function requests(): HasMany
    {
        return $this->hasMany(McpRequest::class, 'mailbox_id');
    }
}
