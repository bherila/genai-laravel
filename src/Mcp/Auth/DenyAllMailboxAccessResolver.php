<?php

namespace Bherila\GenAiLaravel\Mcp\Auth;

use Bherila\GenAiLaravel\Contracts\MailboxAccessResolver;
use Bherila\GenAiLaravel\Mcp\ExecutionContext;
use Bherila\GenAiLaravel\Mcp\Models\McpMailbox;
use Bherila\GenAiLaravel\Mcp\Models\McpRequest;
use Illuminate\Http\Request;

final class DenyAllMailboxAccessResolver implements MailboxAccessResolver
{
    public function resolve(Request $request): ?ExecutionContext
    {
        return null;
    }

    public function authorize(ExecutionContext $context, McpMailbox $mailbox, string $ability, ?McpRequest $request = null): bool
    {
        return false;
    }
}
