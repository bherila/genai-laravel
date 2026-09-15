<?php

namespace Bherila\GenAiLaravel\Mcp\Commands;

use Bherila\GenAiLaravel\Mcp\Enums\McpRequestStatus;
use Bherila\GenAiLaravel\Mcp\Events\McpRequestExpired;
use Bherila\GenAiLaravel\Mcp\Events\McpRequestFailed;
use Bherila\GenAiLaravel\Mcp\Models\McpDelivery;
use Bherila\GenAiLaravel\Mcp\Models\McpRequest;
use Bherila\GenAiLaravel\Mcp\Models\McpToken;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class PruneMcpRequests extends Command
{
    protected $signature = 'genai:mcp:prune';

    protected $description = 'Expire stale work and prune acknowledged terminal MCP requests and owned attachments.';

    public function handle(): int
    {
        do {
            $expired = DB::transaction(function (): int {
                $requests = McpRequest::query()
                    ->whereIn('status', [McpRequestStatus::Pending->value, McpRequestStatus::Leased->value])
                    ->whereNotNull('expires_at')->where('expires_at', '<=', now())
                    ->lockForUpdate()->limit(100)->get();
                foreach ($requests as $request) {
                    if (! in_array($request->status, [McpRequestStatus::Pending, McpRequestStatus::Leased], true)) {
                        continue;
                    }
                    $request->forceFill([
                        'status' => McpRequestStatus::Expired,
                        'lease_token_hash' => null,
                        'lease_expires_at' => null,
                        'lease_principal' => null,
                    ])->save();
                    DB::afterCommit(fn () => event(new McpRequestExpired($request->id)));
                }

                return $requests->count();
            });
        } while ($expired === 100);

        do {
            $finalized = DB::transaction(function (): int {
                $requests = McpRequest::query()->where('status', McpRequestStatus::Leased->value)
                    ->where('lease_expires_at', '<=', now())
                    ->whereColumn('attempt_count', '>=', 'max_attempts')
                    ->lockForUpdate()->limit(100)->get();
                foreach ($requests as $request) {
                    $error = ['code' => 'attempts_exhausted', 'message' => 'The final executor lease expired.'];
                    $request->forceFill([
                        'status' => McpRequestStatus::Failed,
                        'failed_at' => now(),
                        'error' => $error,
                        'lease_token_hash' => null,
                        'lease_expires_at' => null,
                        'lease_principal' => null,
                    ])->save();
                    McpDelivery::query()->firstOrCreate(
                        ['request_id' => $request->id, 'type' => 'failed'],
                        ['payload' => ['error' => $error], 'available_at' => now()],
                    );
                    DB::afterCommit(fn () => event(new McpRequestFailed($request->id, true)));
                }

                return $requests->count();
            });
        } while ($finalized === 100);

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
