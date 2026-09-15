<?php

namespace Bherila\GenAiLaravel\Mcp\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $request_id
 * @property string|null $disk
 * @property string|null $path
 * @property string|null $host_reference
 * @property string $name
 * @property string $mime_type
 * @property int $size
 * @property string $sha256
 * @property bool $package_owned
 */
final class McpAttachment extends Model
{
    use HasUuids;

    protected $table = 'genai_mcp_attachments';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['package_owned' => 'boolean'];
    }
}
