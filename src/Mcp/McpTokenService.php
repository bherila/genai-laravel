<?php

namespace Bherila\GenAiLaravel\Mcp;

use Bherila\GenAiLaravel\Mcp\Models\McpMailbox;
use Bherila\GenAiLaravel\Mcp\Models\McpToken;
use DateTimeInterface;
use Illuminate\Support\Str;

final class McpTokenService
{
    /** @param list<string> $scopes */
    public function issue(McpMailbox $mailbox, string $name, array $scopes = ['genai:read', 'genai:work'], ?DateTimeInterface $expiresAt = null): string
    {
        if (! (bool) config('genai.mcp.personal_tokens.enabled', false)) {
            throw new \LogicException('The optional GenAI MCP personal-token adapter is disabled.');
        }
        if (! $mailbox->enabled || trim($name) === '') {
            throw new \InvalidArgumentException('Issue tokens only for an enabled mailbox and provide a name.');
        }
        $scopes = array_values(array_unique($scopes));
        if ($scopes === [] || array_diff($scopes, ['genai:read', 'genai:work']) !== []) {
            throw new \InvalidArgumentException('Token scopes must contain genai:read and/or genai:work.');
        }
        if ($expiresAt !== null && $expiresAt <= now()) {
            throw new \InvalidArgumentException('Token expiration must be in the future.');
        }
        $plain = 'genai_mcp_'.Str::random(64);
        McpToken::query()->create([
            'mailbox_id' => $mailbox->id, 'name' => Str::limit($name, 191, ''),
            'token_hash' => hash('sha256', $plain), 'scopes' => $scopes,
            'expires_at' => $expiresAt,
        ]);

        return $plain;
    }

    public function revoke(McpToken $token): void
    {
        $token->forceFill(['revoked_at' => now()])->save();
    }
}
