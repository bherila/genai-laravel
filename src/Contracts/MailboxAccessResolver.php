<?php

namespace Bherila\GenAiLaravel\Contracts;

use Bherila\GenAiLaravel\Mcp\ExecutionContext;
use Bherila\GenAiLaravel\Mcp\Models\McpMailbox;
use Bherila\GenAiLaravel\Mcp\Models\McpRequest;
use Illuminate\Http\Request;

interface MailboxAccessResolver
{
    public function resolve(Request $request): ?ExecutionContext;

    public function authorize(ExecutionContext $context, McpMailbox $mailbox, string $ability, ?McpRequest $request = null): bool;
}
