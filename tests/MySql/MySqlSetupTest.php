<?php

namespace Tests\MySql;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\MySqlTestCase;

/** Phase 9B: the MySQL run is on the guarded test database, in Laravel's strict mode. */
class MySqlSetupTest extends MySqlTestCase
{
    public function test_the_live_connection_is_the_test_database_in_strict_mode(): void
    {
        $this->assertStringEndsWith('_test', DB::selectOne('select database() as name')->name);

        $mode = (string) DB::selectOne('select @@SESSION.sql_mode as mode')->mode;
        foreach (['ONLY_FULL_GROUP_BY', 'STRICT_TRANS_TABLES', 'NO_ZERO_DATE', 'ERROR_FOR_DIVISION_BY_ZERO'] as $flag) {
            $this->assertStringContainsString($flag, $mode);
        }

        // The schema is there and the tables are empty but for the chain head
        $this->assertSame(1, DB::table('audit_chain_head')->count());
        $this->assertSame(0, DB::table('users')->count());
    }

    /** Every migration's down() works on MySQL, in reverse order, with the foreign keys checked; then up again. */
    public function test_every_migration_reverts_and_applies_again(): void
    {
        $this->assertLiveTestDatabase();
        $count = DB::table('migrations')->count();

        $this->assertSame(0, Artisan::call('migrate:reset', ['--force' => true]));
        $left = DB::select("select table_name as name from information_schema.tables where table_schema = database() and table_type = 'BASE TABLE' and table_name <> 'migrations'");
        $this->assertSame([], array_column($left, 'name'), 'down() left tables behind');

        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $this->assertSame($count, DB::table('migrations')->count());
        $this->assertSame(1, DB::table('audit_chain_head')->count(), 'The migration puts the chain head back');
    }
}
