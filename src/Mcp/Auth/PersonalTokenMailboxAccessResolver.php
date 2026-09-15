<?php

namespace Bherila\GenAiLaravel\Mcp\Auth;

use Bherila\GenAiLaravel\Contracts\MailboxAccessResolver;
use Bherila\GenAiLaravel\Mcp\ExecutionContext;
use Bherila\GenAiLaravel\Mcp\Models\McpMailbox;
use Bherila\GenAiLaravel\Mcp\Models\McpRequest;
use Bherila\GenAiLaravel\Mcp\Models\McpToken;
use Illuminate\Http\Request;

final class PersonalTokenMailboxAccessResolver implements MailboxAccessResolver
{
    public function resolve(Request $request): ?ExecutionContext
    {
        $plain = $request->bearerToken();
        if (! is_string($plain) || ! str_starts_with($plain, 'genai_mcp_')) {
            return null;
        }
        $token = McpToken::query()->where('token_hash', hash('sha256', $plain))
            ->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->first();
        if ($token === null) {
            return null;
        }
        $token->forceFill(['last_used_at' => now()])->save();

        return new ExecutionContext('token:'.$token->id, [$token->mailbox_id], $token->scopes ?? []);
    }

    public function authorize(ExecutionContext $context, McpMailbox $mailbox, string $ability, ?McpRequest $request = null): bool
    {
        return $mailbox->enabled && in_array($mailbox->id, $context->mailboxIds, true) && $context->can($ability);
    }
}
