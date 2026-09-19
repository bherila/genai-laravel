<?php

namespace Bherila\GenAiLaravel\Tests\Feature;

use Bherila\GenAiLaravel\ContentBlock;
use Bherila\GenAiLaravel\Contracts\CompletionDelivery;
use Bherila\GenAiLaravel\Contracts\MailboxAccessResolver;
use Bherila\GenAiLaravel\GenAiRequest;
use Bherila\GenAiLaravel\GenAiServiceProvider;
use Bherila\GenAiLaravel\Mcp\Exceptions\McpQueueException;
use Bherila\GenAiLaravel\Mcp\ExecutionContext;
use Bherila\GenAiLaravel\Mcp\LeaseTokenFactory;
use Bherila\GenAiLaravel\Mcp\McpClientFactory;
use Bherila\GenAiLaravel\Mcp\McpQueueService;
use Bherila\GenAiLaravel\Mcp\Models\McpClaimReceipt;
use Bherila\GenAiLaravel\Mcp\Models\McpDelivery;
use Bherila\GenAiLaravel\Mcp\Models\McpMailbox;
use Bherila\GenAiLaravel\Mcp\Models\McpRequest;
use Bherila\GenAiLaravel\Schema;
use Bherila\GenAiLaravel\ToolChoice;
use Bherila\GenAiLaravel\ToolConfig;
use Bherila\GenAiLaravel\ToolDefinition;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Orchestra\Testbench\TestCase;
use PDOException;

/** Lease-ownership and idempotency integrity for the queue (#44, #34, #40, #31). */
final class McpQueueIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private ExecutionContext $context;

    protected function getPackageProviders($app): array
    {
        return [GenAiServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('x', 32)));
        $app['config']->set('genai.mcp.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate')->run();
        Storage::fake('local');
        $this->context = new ExecutionContext('test-principal', [], ['genai:read', 'genai:work']);
        $this->app->instance(MailboxAccessResolver::class, new IntegrityMailboxAccessResolver($this->context));
        ClaimRaceState::$collided = false;
    }

    public function test_a_slow_delivery_worker_cannot_clear_a_newer_owners_lease(): void
    {
        $delivery = $this->completedDelivery();
        $takeover = ['lease_owner' => 'newer-owner', 'leased_until' => now()->addMinutes(5)];
        // The handler outlives its lease: another worker takes the record over mid-flight.
        $this->app->instance(CompletionDelivery::class, new TakeoverCompletionDelivery($delivery->id, $takeover, true));

        $this->artisan('genai:mcp:deliver')->assertSuccessful();

        $delivery->refresh();
        $this->assertNull($delivery->acknowledged_at, 'The stale worker acknowledged a record it no longer owned.');
        $this->assertSame('newer-owner', $delivery->lease_owner);
        $this->assertSame($takeover['leased_until']->toDateTimeString(), $delivery->leased_until->toDateTimeString());
    }

    public function test_a_failing_slow_worker_cannot_reschedule_a_newer_owners_lease(): void
    {
        $delivery = $this->completedDelivery();
        $this->app->instance(CompletionDelivery::class, new TakeoverCompletionDelivery($delivery->id, ['lease_owner' => 'newer-owner', 'leased_until' => now()->addMinutes(5)], false));

        $this->artisan('genai:mcp:deliver')->assertSuccessful();

        $delivery->refresh();
        $this->assertSame('newer-owner', $delivery->lease_owner);
        $this->assertNull($delivery->last_error);
    }

    public function test_the_owning_worker_still_acknowledges_and_counts_one_attempt(): void
    {
        $delivery = $this->completedDelivery();
        $this->app->instance(CompletionDelivery::class, new TakeoverCompletionDelivery($delivery->id, [], true));

        $this->artisan('genai:mcp:deliver')->assertSuccessful();

        $delivery->refresh();
        $this->assertNotNull($delivery->acknowledged_at);
        $this->assertNull($delivery->lease_owner);
        $this->assertSame(1, $delivery->attempt_count);
    }

    public function test_renewing_a_lease_extends_the_claim_receipt_so_the_key_still_replays(): void
    {
        $mailbox = $this->mailbox();
        GenAiRequest::with($this->app->make(McpClientFactory::class)->forMailbox($mailbox))->prompt('Hi')->enqueue();
        $service = $this->app->make(McpQueueService::class);
        $claim = $service->claim($this->context, idempotencyKey: 'runner:1');
        $receiptExpiry = McpClaimReceipt::query()->where('idempotency_key', 'runner:1')->firstOrFail()->expires_at;

        // Past the original lease, inside the renewed one: a restarted runner replays its key.
        $this->travel(10)->minutes();
        $renewed = $service->renew($this->context, $claim['request']['id'], $claim['request']['lease_token']);
        $this->assertTrue(McpClaimReceipt::query()->where('idempotency_key', 'runner:1')->firstOrFail()->expires_at->greaterThan($receiptExpiry));

        $this->travel(6)->minutes();
        $replay = $service->claim($this->context, idempotencyKey: 'runner:1');
        $this->assertSame($claim['request']['id'], $replay['request']['id']);
        $this->assertSame($claim['request']['lease_token'], $replay['request']['lease_token']);
        $this->assertSame($renewed['request']['lease_expires_at'], $replay['request']['lease_expires_at']);
    }

    public function test_renewal_does_not_resurrect_a_superseded_claim_key(): void
    {
        $mailbox = $this->mailbox();
        GenAiRequest::with($this->app->make(McpClientFactory::class)->forMailbox($mailbox))->prompt('Hi')->enqueue();
        $service = $this->app->make(McpQueueService::class);
        $first = $service->claim($this->context, idempotencyKey: 'old-key');

        // The first lease and its receipt expire; the same principal reclaims
        // the request under a new key and renews that lease.
        $this->travel(31)->minutes();
        $second = $service->claim($this->context, idempotencyKey: 'new-key');
        $this->assertNotSame($first['request']['lease_token'], $second['request']['lease_token']);
        $service->renew($this->context, $second['request']['id'], $second['request']['lease_token']);

        $old = McpClaimReceipt::query()->where('idempotency_key', 'old-key')->first();
        $this->assertTrue($old === null || $old->expires_at->isPast(), 'A superseded receipt was extended.');
        $replay = $service->claim($this->context, idempotencyKey: 'old-key');
        $this->assertTrue($replay === null || $replay['request']['lease_token'] !== $first['request']['lease_token'], 'A stale key replayed a token that no longer opens the lease.');
    }

    public function test_a_lost_race_on_a_claim_key_replays_the_winner_instead_of_erroring(): void
    {
        $mailbox = $this->mailbox();
        GenAiRequest::with($this->app->make(McpClientFactory::class)->forMailbox($mailbox))->prompt('Hi')->enqueue();
        $service = $this->app->make(McpQueueService::class);
        $winner = null;

        // This invocation loses the race: its receipt insert hits the unique
        // index, and the competing claim commits while it rolls back.
        McpClaimReceipt::creating(function (): void {
            if (ClaimRaceState::$collided) {
                return;
            }
            ClaimRaceState::$collided = true;
            throw new UniqueConstraintViolationException('testing', 'insert into genai_mcp_claim_receipts', [], new PDOException('UNIQUE constraint failed'));
        });
        Event::listen(TransactionRolledBack::class, function () use ($service, &$winner): void {
            if (ClaimRaceState::$collided && $winner === null) {
                $winner = $service->claim($this->context, idempotencyKey: 'race:1');
            }
        });

        $claim = $service->claim($this->context, idempotencyKey: 'race:1');

        $this->assertTrue(ClaimRaceState::$collided, 'The race was never exercised.');
        $this->assertNotNull($winner);
        $this->assertSame($winner['request']['id'], $claim['request']['id']);
        $this->assertSame($winner['request']['lease_token'], $claim['request']['lease_token']);
        $this->assertSame(1, McpClaimReceipt::query()->where('idempotency_key', 'race:1')->count());
        $this->assertSame(1, McpRequest::query()->firstOrFail()->attempt_count, 'The rolled-back attempt was counted.');
    }

    public function test_queued_tool_calls_get_stable_unique_ids_that_survive_replay(): void
    {
        $mailbox = $this->mailbox();
        $pending = GenAiRequest::with($this->app->make(McpClientFactory::class)->forMailbox($mailbox))
            ->tools(new ToolConfig([new ToolDefinition('ping', 'Ping', Schema::object(['n' => Schema::number()]))], ToolChoice::any()))
            ->prompt('Ping twice')->enqueue();
        $service = $this->app->make(McpQueueService::class);
        $claim = $service->claim($this->context);
        $submitted = ['tool_calls' => [
            ['name' => 'ping', 'input' => ['n' => 1]],
            ['name' => 'ping', 'input' => ['n' => 2]],
        ]];

        $first = $service->complete($this->context, $pending->id, $claim['request']['lease_token'], $submitted);
        $calls = $pending->response()?->toolCalls ?? [];

        $this->assertCount(2, $calls);
        $this->assertNotSame('', $calls[0]['id']);
        $this->assertNotSame($calls[0]['id'], $calls[1]['id']);
        // Same submission replays to the same receipt, so ids cannot drift.
        $this->assertSame($first, $service->complete($this->context, $pending->id, $claim['request']['lease_token'], $submitted));
        $this->assertSame(array_column($calls, 'id'), array_column($pending->response()?->toolCalls ?? [], 'id'));

        $result = ContentBlock::toolResultFor($calls[1], 'pong');
        $this->assertSame($calls[1]['id'], $result->toolCallId);
    }

    public function test_an_executor_keeps_its_own_tool_call_ids_and_duplicates_are_refused(): void
    {
        $mailbox = $this->mailbox();
        $tools = new ToolConfig([new ToolDefinition('ping', 'Ping', Schema::object(['n' => Schema::number()]))], ToolChoice::any());
        $service = $this->app->make(McpQueueService::class);

        $kept = GenAiRequest::with($this->app->make(McpClientFactory::class)->forMailbox($mailbox))->tools($tools)->prompt('One')->enqueue();
        $claim = $service->claim($this->context);
        $service->complete($this->context, $kept->id, $claim['request']['lease_token'], ['tool_calls' => [['id' => 'toolu_executor_1', 'name' => 'ping', 'input' => ['n' => 1]]]]);
        $this->assertSame('toolu_executor_1', ($kept->response()?->toolCalls ?? [])[0]['id']);

        $dupe = GenAiRequest::with($this->app->make(McpClientFactory::class)->forMailbox($mailbox))->tools($tools)->prompt('Two')->enqueue();
        $claim = $service->claim($this->context);
        $this->expectException(McpQueueException::class);
        $service->complete($this->context, $dupe->id, $claim['request']['lease_token'], ['tool_calls' => [
            ['id' => 'same', 'name' => 'ping', 'input' => ['n' => 1]],
            ['id' => 'same', 'name' => 'ping', 'input' => ['n' => 2]],
        ]]);
    }

    public function test_a_lost_race_that_leaves_no_candidate_still_replays_the_winner(): void
    {
        $mailbox = $this->mailbox();
        $pending = GenAiRequest::with($this->app->make(McpClientFactory::class)->forMailbox($mailbox))->prompt('Only one')->enqueue();
        $service = $this->app->make(McpQueueService::class);
        // Another principal holds the only request, so this call finds no candidate.
        $service->claim(new ExecutionContext('other-principal', [$mailbox->id], ['genai:work']));

        // The competing first use of our key commits after our candidate query
        // and before the recheck: the state a lost race actually leaves behind.
        $mailboxId = $mailbox->id;
        Event::listen(QueryExecuted::class, function (QueryExecuted $query) use ($pending, $mailboxId): void {
            // The candidate select specifically, not the earlier sweeps.
            if (ClaimRaceState::$collided || ! str_contains($query->sql, 'order by "priority" desc')) {
                return;
            }
            ClaimRaceState::$collided = true;
            McpClaimReceipt::query()->create([
                'id' => (string) Str::uuid(), 'request_id' => $pending->id, 'mailbox_id' => $mailboxId,
                'principal_key' => 'test-principal', 'idempotency_key' => 'sole:1', 'queue_filter' => null,
                'expires_at' => now()->addMinutes(10),
            ]);
        });

        $claim = $service->claim($this->context, idempotencyKey: 'sole:1');

        $this->assertTrue(ClaimRaceState::$collided, 'The race was never exercised.');
        $this->assertNotNull($claim, 'The loser returned null instead of replaying the winning receipt.');
        $this->assertSame($pending->id, $claim['request']['id']);
        $winning = McpClaimReceipt::query()->where('idempotency_key', 'sole:1')->firstOrFail();
        $this->assertSame($this->app->make(LeaseTokenFactory::class)->forReceipt($winning), $claim['request']['lease_token']);
    }

    public function test_a_malformed_supplied_tool_call_id_is_rejected_not_replaced(): void
    {
        $mailbox = $this->mailbox();
        $tools = new ToolConfig([new ToolDefinition('ping', 'Ping', Schema::object(['n' => Schema::number()]))], ToolChoice::any());
        $pending = GenAiRequest::with($this->app->make(McpClientFactory::class)->forMailbox($mailbox))->tools($tools)->prompt('One')->enqueue();
        $service = $this->app->make(McpQueueService::class);
        $claim = $service->claim($this->context);

        try {
            $service->complete($this->context, $pending->id, $claim['request']['lease_token'], ['tool_calls' => [['id' => '', 'name' => 'ping', 'input' => ['n' => 1]]]]);
            $this->fail('An empty tool call id was accepted.');
        } catch (McpQueueException $e) {
            $this->assertSame(422, $e->httpStatus);
        }
    }

    private function completedDelivery(): McpDelivery
    {
        $mailbox = $this->mailbox();
        $pending = GenAiRequest::with($this->app->make(McpClientFactory::class)->forMailbox($mailbox))->prompt('Hi')->enqueue();
        $service = $this->app->make(McpQueueService::class);
        $claim = $service->claim($this->context);
        $service->complete($this->context, $pending->id, $claim['request']['lease_token'], ['text' => 'done']);

        return McpDelivery::query()->where('request_id', $pending->id)->firstOrFail();
    }

    private function mailbox(): McpMailbox
    {
        $mailbox = McpMailbox::query()->create(['owner_type' => 'user', 'owner_id' => '1', 'name' => 'default', 'enabled' => true]);
        $this->context = new ExecutionContext('test-principal', [$mailbox->id], ['genai:read', 'genai:work']);
        $this->app->instance(MailboxAccessResolver::class, new IntegrityMailboxAccessResolver($this->context));

        return $mailbox;
    }
}

final class ClaimRaceState
{
    public static bool $collided = false;
}

/** Mutates the record while the handler runs, as a concurrent worker would. */
final class TakeoverCompletionDelivery implements CompletionDelivery
{
    /** @param array<string, mixed> $takeover */
    public function __construct(private string $deliveryId, private array $takeover, private bool $result) {}

    public function deliver(McpDelivery $delivery): bool
    {
        if ($this->takeover !== []) {
            McpDelivery::query()->whereKey($this->deliveryId)->update($this->takeover);
        }

        return $this->result;
    }
}

final class IntegrityMailboxAccessResolver implements MailboxAccessResolver
{
    public function __construct(public ExecutionContext $context) {}

    public function resolve(Request $request): ?ExecutionContext
    {
        return $this->context;
    }

    public function authorize(ExecutionContext $context, McpMailbox $mailbox, string $ability, ?McpRequest $request = null): bool
    {
        return $mailbox->enabled && in_array($mailbox->id, $context->mailboxIds, true) && $context->can($ability);
    }
}
