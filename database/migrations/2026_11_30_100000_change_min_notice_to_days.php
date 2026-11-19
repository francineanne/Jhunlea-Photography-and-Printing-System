<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10I: the minimum notice is counted in whole Manila calendar days, not hours, and is never 0 (same-day
 * photoshoots are never allowed). The saved value is carried over as max(1, ceil(hours / 24)): 24 h -> 1, 30 h -> 2,
 * 48 h -> 2, 0 h -> 1. No booking is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('min_notice_days')->default(1)->after('buffer_minutes');
        });

        foreach (DB::table('booking_settings')->get(['id', 'min_notice_hours']) as $row) {
            DB::table('booking_settings')->where('id', $row->id)->update(['min_notice_days' => self::days((int) $row->min_notice_hours)]);
        }

        Schema::table('booking_settings', function (Blueprint $table) {
            $table->dropColumn('min_notice_hours');
        });
    }

    public function down(): void
    {
        Schema::table('booking_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('min_notice_hours')->default(24)->after('buffer_minutes');
        });

        foreach (DB::table('booking_settings')->get(['id', 'min_notice_days']) as $row) {
            DB::table('booking_settings')->where('id', $row->id)->update(['min_notice_hours' => (int) $row->min_notice_days * 24]);
        }

        Schema::table('booking_settings', function (Blueprint $table) {
            $table->dropColumn('min_notice_days');
        });
    }

    /** The one conversion: whole days, at least 1. */
    public static function days(int $hours): int
    {
        return max(1, (int) ceil(max(0, $hours) / 24));
    }
};
