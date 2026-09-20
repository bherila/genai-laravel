<?php

namespace Bherila\GenAiLaravel\Tests\StaticAnalysis;

use Bherila\GenAiLaravel\Mcp\Enums\McpRequestStatus;
use Bherila\GenAiLaravel\Mcp\Models\McpAttachment;
use Bherila\GenAiLaravel\Mcp\Models\McpClaimReceipt;
use Bherila\GenAiLaravel\Mcp\Models\McpDelivery;
use Bherila\GenAiLaravel\Mcp\Models\McpMailbox;
use Bherila\GenAiLaravel\Mcp\Models\McpRequest;
use Bherila\GenAiLaravel\Mcp\Models\McpToken;

/**
 * Never executed — this file exists to be analysed.
 *
 * The MCP models are public API: a host reads their persisted attributes from
 * its own code, and a Larastan consumer reports `property.notFound` for any
 * attribute missing from a model's PHPDoc. Reading every one of them here, in a
 * file PHPStan analyses alongside `src`, means that gap fails in this
 * repository's build rather than in someone else's.
 *
 * Add a line here whenever a persisted attribute is added to one of these
 * models.
 */
final class McpModelPropertyUsage
{
    /** @return list<string> */
    public function mailbox(McpMailbox $mailbox): array
    {
        return [
            $mailbox->id,
            $mailbox->owner_type,
            $mailbox->owner_id,
            $mailbox->name,
            $mailbox->enabled ? 'enabled' : 'disabled',
            (string) $mailbox->requests->count(),
        ];
    }

    /** @return list<string> */
    public function token(McpToken $token): array
    {
        return [
            $token->id,
            $token->mailbox_id,
            $token->name,
            $token->token_hash,
            implode(',', $token->scopes),
            (string) $token->expires_at?->toIso8601String(),
            (string) $token->last_used_at?->toIso8601String(),
            (string) $token->revoked_at?->toIso8601String(),
        ];
    }

    /** @return list<string> */
    public function request(McpRequest $request): array
    {
        return [
            $request->id,
            $request->mailbox_id,
            $request->queue,
            $request->status->value,
            (string) $request->priority,
            json_encode($request->payload) ?: '',
            json_encode($request->metadata) ?: '',
            $request->idempotency_key ?? '',
            (string) $request->attempt_count,
            (string) $request->max_attempts,
            (string) $request->enqueue_hash,
            $request->available_at->toIso8601String(),
            (string) $request->expires_at?->toIso8601String(),
            (string) $request->leased_at?->toIso8601String(),
            (string) $request->lease_expires_at?->toIso8601String(),
            (string) $request->lease_token_hash,
            (string) $request->lease_principal,
            (string) $request->completed_at?->toIso8601String(),
            (string) $request->failed_at?->toIso8601String(),
            json_encode($request->result) ?: '',
            json_encode($request->error) ?: '',
            (string) $request->completion_hash,
            (string) $request->completion_lease_hash,
            (string) $request->completion_principal,
            (string) $request->completion_receipt_id,
            $request->mailbox->name,
            (string) $request->attachments->count(),
            (string) $request->deliveries->count(),
            $request->status === McpRequestStatus::Completed ? 'done' : 'pending',
        ];
    }

    /** @return list<string> */
    public function attachment(McpAttachment $attachment): array
    {
        return [
            $attachment->id,
            $attachment->request_id,
            $attachment->disk ?? '',
            $attachment->path ?? '',
            $attachment->host_reference ?? '',
            $attachment->name,
            $attachment->mime_type,
            (string) $attachment->size,
            $attachment->sha256,
            $attachment->package_owned ? 'package' : 'host',
        ];
    }

    /** @return list<string> */
    public function claimReceipt(McpClaimReceipt $receipt): array
    {
        return [
            $receipt->id,
            $receipt->request_id,
            $receipt->mailbox_id,
            $receipt->principal_key,
            $receipt->idempotency_key,
            $receipt->queue_filter ?? '',
            $receipt->expires_at->toIso8601String(),
        ];
    }

    /** @return list<string> */
    public function delivery(McpDelivery $delivery): array
    {
        return [
            $delivery->id,
            $delivery->request_id,
            $delivery->type,
            json_encode($delivery->payload) ?: '',
            (string) $delivery->attempt_count,
            $delivery->available_at->toIso8601String(),
            (string) $delivery->acknowledged_at?->toIso8601String(),
            (string) $delivery->leased_until?->toIso8601String(),
            $delivery->lease_owner ?? '',
            $delivery->last_error ?? '',
        ];
    }
}
