<?php

namespace Bherila\GenAiLaravel\Contracts;

use Bherila\GenAiLaravel\Mcp\ExecutionContext;
use Bherila\GenAiLaravel\Mcp\Models\McpAttachment;

interface AttachmentResolver
{
    /** @return resource */
    public function readStream(McpAttachment $attachment, ExecutionContext $context);
}
