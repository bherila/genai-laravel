<?php

namespace Bherila\GenAiLaravel\Tests\Feature;

use Bherila\GenAiLaravel\GenAiServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;

/**
 * The MCP migration against the configured connection: SQLite by default, or MariaDB/MySQL when
 * `GENAI_TEST_DB_DRIVER` is set (the CI `mariadb` job), where identifier limits actually apply.
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
}
