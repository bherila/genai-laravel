<?php

namespace Bherila\GenAiLaravel\Mcp\Commands;

use Bherila\GenAiLaravel\Contracts\CompletionDelivery;
use Bherila\GenAiLaravel\Mcp\Models\McpDelivery;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Throwable;

final class DeliverMcpCompletions extends Command
{
    protected $signature = 'genai:mcp:deliver {--limit=100}';

    protected $description = 'Deliver and acknowledge durable MCP completion/failure records.';

    public function handle(CompletionDelivery $delivery, DatabaseManager $db): int
    {
        $processed = 0;
        $limit = max(1, min(1000, (int) $this->option('limit')));
        while ($processed < $limit) {
            $owner = (string) Str::uuid();
            $row = $db->connection()->transaction(function () use ($owner): ?McpDelivery {
                $row = McpDelivery::query()->whereNull('acknowledged_at')->where('available_at', '<=', now())
                    ->where(fn ($query) => $query->whereNull('leased_until')->orWhere('leased_until', '<=', now()))
                    ->orderBy('created_at')->lockForUpdate()->first();
                if ($row === null) {
                    return null;
                }
                $row->forceFill(['lease_owner' => $owner, 'leased_until' => now()->addMinutes(5)])->save();

                return $row;
            });
            if ($row === null) {
                break;
            }
            try {
                $row->increment('attempt_count');
                if ($delivery->deliver($row)) {
                    $row->forceFill(['acknowledged_at' => now(), 'last_error' => null, 'lease_owner' => null, 'leased_until' => null])->save();
                } else {
                    $row->forceFill(['lease_owner' => null, 'leased_until' => null, 'available_at' => now()->addMinute()])->save();
                }
            } catch (Throwable $e) {
                $row->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 1000), 'available_at' => now()->addMinutes(5), 'lease_owner' => null, 'leased_until' => null])->save();
                report($e);
            }
            $processed++;
        }
        $this->info("Processed {$processed} delivery record(s).");

        return self::SUCCESS;
    }
}
