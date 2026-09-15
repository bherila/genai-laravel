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
        $plain = 'genai_mcp_'.Str::random(64);
        McpToken::query()->create([
            'mailbox_id' => $mailbox->id, 'name' => Str::limit($name, 191, ''),
            'token_hash' => hash('sha256', $plain), 'scopes' => array_values(array_unique($scopes)),
            'expires_at' => $expiresAt,
        ]);

        return $plain;
    }

    public function revoke(McpToken $token): void
    {
        $token->forceFill(['revoked_at' => now()])->save();
    }
}
