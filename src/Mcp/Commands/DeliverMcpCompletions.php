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
                // Counted at lease time: a slow worker whose lease was taken over
                // must not increment the attempt of whoever holds it now.
                $row->forceFill([
                    'lease_owner' => $owner, 'leased_until' => now()->addMinutes(5),
                    'attempt_count' => $row->attempt_count + 1,
                ])->save();

                return $row;
            });
            if ($row === null) {
                break;
            }
            try {
                $applied = $delivery->deliver($row)
                    ? $this->owned($row, $owner, ['acknowledged_at' => now(), 'last_error' => null, 'lease_owner' => null, 'leased_until' => null])
                    : $this->owned($row, $owner, ['lease_owner' => null, 'leased_until' => null, 'available_at' => now()->addMinute()]);
            } catch (Throwable $e) {
                $applied = $this->owned($row, $owner, ['last_error' => mb_substr($e->getMessage(), 0, 1000), 'available_at' => now()->addMinutes(5), 'lease_owner' => null, 'leased_until' => null]);
                report($e);
            }
            if (! $applied) {
                $this->warn("Delivery {$row->id} was taken over during handling; its newer lease was left untouched.");
            }
            $processed++;
        }
        $this->info("Processed {$processed} delivery record(s).");

        return self::SUCCESS;
    }

    /**
     * Apply an outcome only while this invocation still owns the lease. A
     * handler that outlives its lease would otherwise clear or reschedule the
     * lease of the worker that has since taken the record over.
     *
     * @param  array<string, mixed>  $values
     */
    private function owned(McpDelivery $row, string $owner, array $values): bool
    {
        return McpDelivery::query()->whereKey($row->id)->where('lease_owner', $owner)->update($values) === 1;
    }
}
