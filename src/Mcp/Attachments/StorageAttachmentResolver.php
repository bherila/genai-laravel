<?php

namespace Bherila\GenAiLaravel\Mcp\Attachments;

use Bherila\GenAiLaravel\Contracts\AttachmentResolver;
use Bherila\GenAiLaravel\Mcp\ExecutionContext;
use Bherila\GenAiLaravel\Mcp\Models\McpAttachment;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class StorageAttachmentResolver implements AttachmentResolver
{
    public function readStream(McpAttachment $attachment, ExecutionContext $context)
    {
        if ($attachment->disk === null || $attachment->path === null || $attachment->host_reference !== null) {
            throw new RuntimeException('The host must bind AttachmentResolver to read opaque attachment references.');
        }
        $stream = Storage::disk($attachment->disk)->readStream($attachment->path);
        if ($stream === null) {
            throw new RuntimeException('Attachment stream is unavailable.');
        }

        return $stream;
    }
}
