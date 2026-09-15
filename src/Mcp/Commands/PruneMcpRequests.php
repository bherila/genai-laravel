<?php

namespace Bherila\GenAiLaravel\Mcp\Commands;

use Bherila\GenAiLaravel\Mcp\Enums\McpRequestStatus;
use Bherila\GenAiLaravel\Mcp\Events\McpRequestExpired;
use Bherila\GenAiLaravel\Mcp\Models\McpRequest;
use Bherila\GenAiLaravel\Mcp\Models\McpToken;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

final class PruneMcpRequests extends Command
{
    protected $signature = 'genai:mcp:prune';

    protected $description = 'Expire stale work and prune acknowledged terminal MCP requests and owned attachments.';

    public function handle(): int
    {
        McpRequest::query()->whereIn('status', [McpRequestStatus::Pending->value, McpRequestStatus::Leased->value])
            ->whereNotNull('expires_at')->where('expires_at', '<=', now())->eachById(function (McpRequest $request): void {
                $request->forceFill(['status' => McpRequestStatus::Expired, 'lease_token_hash' => null, 'lease_expires_at' => null])->save();
                event(new McpRequestExpired($request->id));
            });

        $before = now()->subDays((int) config('genai.mcp.retention.terminal_days', 30));
        McpRequest::query()->whereIn('status', [McpRequestStatus::Completed->value, McpRequestStatus::Failed->value, McpRequestStatus::Cancelled->value, McpRequestStatus::Expired->value])
            ->where('updated_at', '<', $before)
            ->whereDoesntHave('deliveries', fn ($q) => $q->whereNull('acknowledged_at'))
            ->with('attachments')->eachById(function (McpRequest $request): void {
                foreach ($request->attachments as $attachment) {
                    if ($attachment->package_owned && $attachment->disk !== null && $attachment->path !== null) {
                        Storage::disk($attachment->disk)->delete($attachment->path);
                    }
                }
                $request->delete();
            });
        McpToken::query()->whereNotNull('revoked_at')->where('revoked_at', '<', $before)->delete();
        $this->info('GenAI MCP retention pass completed.');

        return self::SUCCESS;
    }
}
