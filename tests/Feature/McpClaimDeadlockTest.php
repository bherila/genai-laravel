<?php

namespace Bherila\GenAiLaravel\Tests\Feature;

use Bherila\GenAiLaravel\Contracts\MailboxAccessResolver;
use Bherila\GenAiLaravel\GenAiRequest;
use Bherila\GenAiLaravel\GenAiServiceProvider;
use Bherila\GenAiLaravel\Mcp\ExecutionContext;
use Bherila\GenAiLaravel\Mcp\McpClientFactory;
use Bherila\GenAiLaravel\Mcp\McpQueueService;
use Bherila\GenAiLaravel\Mcp\Models\McpClaimReceipt;
use Bherila\GenAiLaravel\Mcp\Models\McpMailbox;
use Bherila\GenAiLaravel\Mcp\Models\McpRequest;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase;
use PDOException;

/**
 * Deliberately without RefreshDatabase: its outer transaction would make the
 * claim's transaction a savepoint, and Laravel handles a concurrency error at
 * a nested level by resetting the transaction instead of rolling it back, which
 * production (a top-level transaction) never does.
 */
final class McpClaimDeadlockTest extends TestCase
{
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

    public function test_a_deadlocked_claim_retries_instead_of_surfacing_a_database_error(): void
    {
        $this->artisan('migrate')->run();
        $mailbox = McpMailbox::query()->create(['owner_type' => 'user', 'owner_id' => '1', 'name' => 'default', 'enabled' => true]);
        $context = new ExecutionContext('test-principal', [$mailbox->id], ['genai:read', 'genai:work']);
        $this->app->instance(MailboxAccessResolver::class, new DeadlockMailboxAccessResolver($context));
        GenAiRequest::with($this->app->make(McpClientFactory::class)->forMailbox($mailbox))->prompt('Hi')->enqueue();
        $thrown = false;

        // MySQL can answer the losing racer with a deadlock rather than a unique
        // violation, which Laravel surfaces as DeadlockException, not as a
        // UniqueConstraintViolationException.
        McpClaimReceipt::creating(function () use (&$thrown): void {
            if ($thrown) {
                return;
            }
            $thrown = true;
            throw new QueryException('testing', 'insert into genai_mcp_claim_receipts', [], new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock'));
        });

        $claim = $this->app->make(McpQueueService::class)->claim($context, idempotencyKey: 'deadlock:1');

        $this->assertTrue($thrown, 'The deadlock was never exercised.');
        $this->assertNotNull($claim, 'A deadlocked idempotent claim surfaced a database error.');
        $this->assertSame(1, McpClaimReceipt::query()->where('idempotency_key', 'deadlock:1')->count());
        $this->assertSame(1, $claim['request']['attempt']);
    }
}

final class DeadlockMailboxAccessResolver implements MailboxAccessResolver
{
    public function __construct(private ExecutionContext $context) {}

    public function resolve(Request $request): ?ExecutionContext
    {
        return $this->context;
    }

    public function authorize(ExecutionContext $context, McpMailbox $mailbox, string $ability, ?McpRequest $request = null): bool
    {
        return $mailbox->enabled && in_array($mailbox->id, $context->mailboxIds, true) && $context->can($ability);
    }
}
