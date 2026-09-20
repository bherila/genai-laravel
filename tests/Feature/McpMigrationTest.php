<?php

namespace Bherila\GenAiLaravel\Tests\Feature;

use Bherila\GenAiLaravel\GenAiServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Orchestra\Testbench\TestCase;

/**
 * The MCP migration against the configured connection: SQLite by default, or MariaDB/MySQL when
 * `GENAI_TEST_DB_DRIVER` is set (the CI `mariadb` job), where identifier limits actually apply.
 *
 * SQL Server needs no server here: its null-handling differs from every other driver, and what the
 * migration builds for it is asserted from the statements it would issue.
 */
final class McpMigrationTest extends TestCase
{
    private const MIGRATION = '2026_09_15_000000_create_genai_mcp_tables';

    /** @var list<string> */
    private const TABLES = [
        'genai_mcp_mailboxes',
        'genai_mcp_tokens',
        'genai_mcp_requests',
        'genai_mcp_attachments',
        'genai_mcp_claim_receipts',
        'genai_mcp_deliveries',
    ];

    protected function getPackageProviders($app): array
    {
        return [GenAiServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $driver = getenv('GENAI_TEST_DB_DRIVER') ?: 'sqlite';

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $driver === 'sqlite'
            ? ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']
            : [
                'driver' => $driver,
                'host' => getenv('GENAI_TEST_DB_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('GENAI_TEST_DB_PORT') ?: 3306),
                'database' => getenv('GENAI_TEST_DB_DATABASE') ?: 'genai_test',
                'username' => getenv('GENAI_TEST_DB_USERNAME') ?: 'root',
                'password' => getenv('GENAI_TEST_DB_PASSWORD') ?: '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
            ]);
        // Never connected to: the SQL Server shape is asserted from the statements
        // the migration would issue, so no server is needed to prove it.
        $app['config']->set('database.connections.sqlsrv_probe', [
            'driver' => 'sqlsrv', 'host' => '127.0.0.1', 'port' => 1433,
            'database' => 'genai_probe', 'username' => 'sa', 'password' => '', 'prefix' => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh')->run();
    }

    public function test_migration_creates_every_table_with_identifiers_within_the_mysql_limit(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} was not created.");

            foreach (Schema::getIndexes($table) as $index) {
                $this->assertLessThanOrEqual(64, strlen($index['name']), "Index {$index['name']} on {$table} exceeds MySQL's 64-character identifier limit.");
            }

            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                if ($foreignKey['name'] !== null) {
                    $this->assertLessThanOrEqual(64, strlen($foreignKey['name']), "Foreign key {$foreignKey['name']} on {$table} exceeds MySQL's 64-character identifier limit.");
                }
            }
        }

        $this->assertTrue(Schema::hasIndex('genai_mcp_deliveries', 'genai_mcp_deliveries_pending_idx'));
    }

    public function test_rerun_after_a_partial_run_finishes_the_migration(): void
    {
        // Reproduce a MySQL run that created every table but failed on the delivery index before the
        // migration was recorded: the tables exist, the index does not, and no migrations row exists.
        Schema::table('genai_mcp_deliveries', function (Blueprint $table): void {
            $table->dropIndex('genai_mcp_deliveries_pending_idx');
        });
        DB::table('migrations')->where('migration', self::MIGRATION)->delete();
        $this->assertFalse(Schema::hasIndex('genai_mcp_deliveries', 'genai_mcp_deliveries_pending_idx'));

        $this->artisan('migrate')->assertSuccessful();

        $this->assertTrue(Schema::hasIndex('genai_mcp_deliveries', 'genai_mcp_deliveries_pending_idx'));
        $this->assertTrue(DB::table('migrations')->where('migration', self::MIGRATION)->exists());
        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} is missing after the re-run.");
        }
    }

    /**
     * Ordinary enqueues carry no idempotency key at all, so a mailbox holding
     * several of them at once is the common case, not an edge.
     */
    public function test_a_mailbox_holds_many_requests_without_an_idempotency_key(): void
    {
        $mailbox = $this->insertMailbox();

        foreach (range(1, 3) as $index) {
            $this->insertRequest($mailbox, null);
        }

        $this->assertSame(3, DB::table('genai_mcp_requests')->where('mailbox_id', $mailbox)->count());
    }

    public function test_a_supplied_idempotency_key_is_still_unique_within_a_mailbox(): void
    {
        $mailbox = $this->insertMailbox();
        $this->insertRequest($mailbox, 'invoice:1');

        $this->expectException(UniqueConstraintViolationException::class);
        $this->insertRequest($mailbox, 'invoice:1');
    }

    public function test_the_same_idempotency_key_is_free_in_another_mailbox(): void
    {
        $this->insertRequest($this->insertMailbox('one'), 'invoice:1');
        $this->insertRequest($this->insertMailbox('two'), 'invoice:1');

        $this->assertSame(2, DB::table('genai_mcp_requests')->where('idempotency_key', 'invoice:1')->count());
    }

    /**
     * SQL Server treats two NULLs as equal in a unique index, so the plain
     * constraint every other driver gets would cap a mailbox at one keyless
     * request. It must be a filtered index there instead.
     */
    public function test_sql_server_constrains_only_non_null_idempotency_keys(): void
    {
        $statements = $this->statementsOnSqlServer();
        $onIdempotencyKey = array_values(array_filter(
            $statements,
            static fn (string $sql): bool => str_contains($sql, 'unique index') && str_contains($sql, 'idempotency_key') && str_contains($sql, 'genai_mcp_requests'),
        ));

        $this->assertCount(1, $onIdempotencyKey, 'SQL Server should get exactly one uniqueness rule for enqueue idempotency keys.');
        $this->assertStringContainsString('where "idempotency_key" is not null', $onIdempotencyKey[0]);
        $this->assertStringContainsString('"mailbox_id", "idempotency_key"', $onIdempotencyKey[0]);
    }

    public function test_other_drivers_keep_the_plain_unique_constraint(): void
    {
        $unique = array_values(array_filter(
            Schema::getIndexes('genai_mcp_requests'),
            static fn (array $index): bool => ($index['unique'] ?? false) && array_values((array) $index['columns']) === ['mailbox_id', 'idempotency_key'],
        ));

        $this->assertCount(1, $unique);
    }

    /**
     * The statements the migration issues against a SQL Server connection,
     * captured without executing any of them.
     *
     * @return list<string>
     */
    private function statementsOnSqlServer(): array
    {
        $previous = config('database.default');
        config(['database.default' => 'sqlsrv_probe']);
        Schema::clearResolvedInstance('db.schema');

        try {
            $migration = require dirname(__DIR__, 2).'/database/migrations/'.self::MIGRATION.'.php';
            $captured = DB::connection('sqlsrv_probe')->pretend(static fn () => $migration->up());
        } finally {
            config(['database.default' => $previous]);
            Schema::clearResolvedInstance('db.schema');
        }

        return array_map(static fn (array $query): string => $query['query'], $captured);
    }

    private function insertMailbox(string $name = 'default'): string
    {
        $id = (string) Str::uuid();
        DB::table('genai_mcp_mailboxes')->insert([
            'id' => $id, 'owner_type' => 'user', 'owner_id' => '1', 'name' => $name,
            'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function insertRequest(string $mailbox, ?string $idempotencyKey): void
    {
        DB::table('genai_mcp_requests')->insert([
            'id' => (string) Str::uuid(), 'mailbox_id' => $mailbox, 'queue' => 'default',
            'status' => 'pending', 'priority' => 0, 'payload' => '{}',
            'idempotency_key' => $idempotencyKey, 'available_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
