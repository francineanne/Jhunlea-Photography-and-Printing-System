<?php

namespace Tests\Feature\Notifications;

use App\Services\ReferenceNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 9B-2: the migration that adds the bell's index on notifications (notifiable_type, notifiable_id, created_at)
 * and creates the counter row of every reference prefix. Runs on SQLite (the normal suite) and on MySQL
 * (phpunit.mysql.xml); the whole history is also reverted and applied on MySQL by MySqlSetupTest.
 */
class BellIndexAndCounterRowsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const FILE = '2026_11_14_100000_add_bell_index_and_reference_counter_rows';

    private const INDEX = 'notifications_notifiable_created_at_index';

    private const PREFIXES = [ReferenceNumberService::PRINTING, ReferenceNumberService::BOOKING, ReferenceNumberService::PAYMENT];

    public function test_every_prefix_has_its_counter_row_and_the_bell_has_its_index(): void
    {
        $this->assertEqualsCanonicalizing(self::PREFIXES, DB::table('reference_counters')->pluck('prefix')->all());
        $this->assertSame([0, 0, 0], DB::table('reference_counters')->pluck('last_number')->map(fn ($n) => (int) $n)->all());
        $this->assertTrue(Schema::hasIndex('notifications', self::INDEX));
        $this->assertTrue(Schema::hasIndex('notifications', ['notifiable_type', 'notifiable_id', 'created_at']));
    }

    public function test_the_first_numbers_on_a_fresh_database_still_start_at_one(): void
    {
        $numbers = app(ReferenceNumberService::class);

        $this->assertSame(['PR-000001', 'PB-000001', 'PAY-000001'], array_map(fn (string $prefix) => $numbers->next($prefix), self::PREFIXES));
        $this->assertSame('PR-000002', $numbers->next(ReferenceNumberService::PRINTING));
    }

    public function test_it_reverts_and_applies_again_without_resetting_a_counter(): void
    {
        $this->changesSchema(); // DDL: see Tests\TestCase (MySQL commits it at once)
        DB::table('reference_counters')->where('prefix', ReferenceNumberService::PRINTING)->update(['last_number' => 41]);
        $migration = require database_path('migrations/'.self::FILE.'.php');

        $migration->down();
        $this->assertFalse(Schema::hasIndex('notifications', self::INDEX), 'down() drops the index');
        $this->assertSame(3, DB::table('reference_counters')->count(), 'down() keeps the counter rows');

        $migration->up();
        $this->assertTrue(Schema::hasIndex('notifications', self::INDEX));
        $this->assertSame(41, (int) DB::table('reference_counters')->where('prefix', ReferenceNumberService::PRINTING)->value('last_number'), 'An existing counter is never lowered');
        $this->assertSame('PR-000042', app(ReferenceNumberService::class)->next());
        $this->assertSame(3, DB::table('reference_counters')->count());
    }
}
