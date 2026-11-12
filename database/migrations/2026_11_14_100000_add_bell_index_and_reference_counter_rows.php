<?php

use App\Services\ReferenceNumberService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9B-2, found by the MySQL tests (docs/MYSQL_TESTING.md):
 * 1. The notification bell's "latest 8" sorted every notification of the owner on every owner page (about 400 ms at
 *    25,000 rows; about 2 ms with this index).
 * 2. The counter row of a reference prefix was created on first use, so the very first number of a prefix could still
 *    deadlock. Every prefix now has its row from the start. insertOrIgnore: an existing counter is never lowered.
 * down() drops the index only; the counter rows stay (they hold the numbers already handed out).
 */
return new class extends Migration
{
    private const INDEX = 'notifications_notifiable_created_at_index';

    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->index(['notifiable_type', 'notifiable_id', 'created_at'], self::INDEX);
        });

        DB::table('reference_counters')->insertOrIgnore(array_map(
            fn (string $prefix) => ['prefix' => $prefix, 'last_number' => 0],
            [ReferenceNumberService::PRINTING, ReferenceNumberService::BOOKING, ReferenceNumberService::PAYMENT],
        ));
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(self::INDEX);
        });
    }
};
