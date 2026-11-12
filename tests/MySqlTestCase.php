<?php

namespace Tests;

use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\TestDatabaseGuard;

/**
 * Base class of tests/MySql (Phase 9B): real MySQL, the guarded jhunlea_test database only (phpunit.mysql.xml,
 * docs/MYSQL_TESTING.md). It does NOT use RefreshDatabase: its wrapping transaction would hide every row from the
 * parallel worker processes of the race tests. Instead the schema is migrated fresh once per run, and every table is
 * emptied before each test (the audit chain head is put back, as the migration does).
 */
abstract class MySqlTestCase extends TestCase
{
    private static bool $migrated = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! TestDatabaseGuard::mysqlMode() || ! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('MySQL only: run with phpunit.mysql.xml (docs/MYSQL_TESTING.md).');
        }

        // createApplication() already ran the guard; ask the server again right before anything is written
        $this->assertLiveTestDatabase();

        if (! self::$migrated) {
            Artisan::call('migrate:fresh', ['--force' => true]);
            self::$migrated = true;
        }

        $this->emptyTables();
    }

    protected function assertLiveTestDatabase(): void
    {
        $live = TestDatabaseGuard::liveDatabaseName($this->app);

        if (! str_ends_with($live, '_test')) {
            throw new RuntimeException("Refusing to write: the live connection is \"{$live}\", not a database ending with \"_test\".");
        }
    }

    /** Every table but `migrations`, emptied (auto increments reset), then the chain head row the migration inserts. */
    protected function emptyTables(): void
    {
        $this->assertLiveTestDatabase();

        // Only the tables of the database this connection opened (the guarded _test one), never another schema
        $tables = array_values(array_diff(array_map(
            fn ($row) => (string) $row->name,
            DB::select("select table_name as name from information_schema.tables where table_schema = database() and table_type = 'BASE TABLE'"),
        ), ['migrations']));

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($tables as $table) {
                DB::table($table)->truncate();
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        DB::table(AuditLogger::HEAD)->insert(['id' => 1, 'last_id' => null, 'last_hash' => AuditLogger::genesis()]);
    }
}
