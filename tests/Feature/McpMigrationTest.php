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
        $app['config']->set('database.connections.mysql_probe', [
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306,
            'database' => 'genai_probe', 'username' => 'root', 'password' => '', 'prefix' => '',
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

    public function test_required_scheduling_instants_compile_without_timestamp_auto_update_behavior(): void
    {
        $previous = config('database.default');
        config(['database.default' => 'mysql_probe']);
        Schema::clearResolvedInstance('db.schema');

        try {
            $captured = DB::connection('mysql_probe')->pretend(static function (): void {
                foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') as $path) {
                    $migration = require $path;
                    $migration->up();
                }
            });
        } finally {
            config(['database.default' => $previous]);
            Schema::clearResolvedInstance('db.schema');
        }

        foreach ($this->schedulingColumns() as $table => $column) {
            $definitions = array_values(array_filter(
                array_column($captured, 'query'),
                static fn (string $sql): bool => str_contains($sql, '`'.$table.'`')
                    && preg_match('/`'.preg_quote($column, '/').'` (?:timestamp|datetime)/', $sql) === 1
                    && (str_starts_with($sql, 'create table') || str_starts_with($sql, 'alter table')),
            ));
            $this->assertNotEmpty($definitions);
            $this->assertStringContainsString('`'.$column.'` datetime not null', $definitions[array_key_last($definitions)]);
        }
    }

    public function test_forward_migration_preserves_required_scheduling_instants_during_unrelated_updates(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            // Reproduce legacy servers where the first required TIMESTAMP acquires ON UPDATE.
            DB::statement('set session explicit_defaults_for_timestamp = 0');
        }
        $this->artisan('migrate:fresh', [
            '--path' => dirname(__DIR__, 2).'/database/migrations/'.self::MIGRATION.'.php',
            '--realpath' => true,
        ])->assertSuccessful();

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            foreach ($this->schedulingColumns() as $table => $column) {
                $metadata = DB::selectOne(
                    'select EXTRA as extra from information_schema.COLUMNS where TABLE_SCHEMA = database() and TABLE_NAME = ? and COLUMN_NAME = ?',
                    [$table, $column],
                );
                $this->assertStringContainsString('on update', strtolower($metadata->extra));
            }
        }

        $instant = '2001-02-03 04:05:06';
        $mailbox = $this->insertMailbox();
        $request = (string) Str::uuid();
        DB::table('genai_mcp_requests')->insert([
            'id' => $request, 'mailbox_id' => $mailbox, 'queue' => 'default',
            'status' => 'pending', 'priority' => 0, 'payload' => '{}',
            'available_at' => $instant, 'created_at' => $instant, 'updated_at' => $instant,
        ]);
        DB::table('genai_mcp_deliveries')->insert([
            'id' => (string) Str::uuid(), 'request_id' => $request, 'type' => 'result',
            'payload' => '{}', 'attempt_count' => 0, 'available_at' => $instant,
            'created_at' => $instant, 'updated_at' => $instant,
        ]);
        DB::table('genai_mcp_claim_receipts')->insert([
            'id' => (string) Str::uuid(), 'request_id' => $request, 'mailbox_id' => $mailbox,
            'principal_key' => 'user:1', 'idempotency_key' => 'claim:1', 'expires_at' => $instant,
            'created_at' => $instant, 'updated_at' => $instant,
        ]);

        $this->artisan('migrate')->assertSuccessful();
        $this->assertTrue(DB::table('migrations')->where('migration', '2026_10_22_000000_preserve_genai_mcp_scheduling_instants')->exists());

        foreach ($this->schedulingColumns() as $table => $column) {
            $this->assertSame($instant, DB::table($table)->value($column), $table.' changed during migration.');
            $metadata = collect(Schema::getColumns($table))->firstWhere('name', $column);
            $this->assertSame('datetime', $metadata['type_name']);
            $this->assertFalse($metadata['nullable']);
        }

        DB::table('genai_mcp_requests')->update(['attempt_count' => 1]);
        DB::table('genai_mcp_deliveries')->update(['attempt_count' => 1]);
        DB::table('genai_mcp_claim_receipts')->update(['queue_filter' => 'default']);

        foreach ($this->schedulingColumns() as $table => $column) {
            $this->assertSame($instant, DB::table($table)->value($column), $table.' changed during an unrelated update.');
            DB::table($table)->update([$column => '2001-03-04 05:06:07']);
            $this->assertSame('2001-03-04 05:06:07', DB::table($table)->value($column));
        }
    }

    /** @return array<string, string> */
    private function schedulingColumns(): array
    {
        return [
            'genai_mcp_requests' => 'available_at',
            'genai_mcp_deliveries' => 'available_at',
            'genai_mcp_claim_receipts' => 'expires_at',
        ];
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
