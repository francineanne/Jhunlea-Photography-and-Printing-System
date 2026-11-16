<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10A (scheduling). Safe on a database that holds rows: it only ADDS columns and fills them; no booking's
 * status, time or price is changed.
 *
 * 1. photography_bookings.hold_expires_at (UTC, nullable): until when a Requested or Proposed booking holds its time
 *    (PhotographyBooking::scopeHoldingTime is the one rule). Existing open requests get a hold of the default length
 *    from the moment of this migration ONLY when their time (the proposed time for a proposal) is still ahead AND the
 *    time is not already held by a confirmed booking or by an older open request: two old requests for the same time
 *    must not both hold it, or neither could be confirmed. The oldest request wins; the others stay as they are,
 *    without a hold, for the owner to act on.
 * 2. photography_packages.min_people (default 1).
 * 3. booking_settings.request_hold_hours (default 48) and max_sessions_per_day (NULL = no limit).
 * 4. booking_settings.concurrent_sessions is set to 1 (the shop has one studio). The column stays.
 * 5. shop_settings.opening_hours: the shop's own opening hours as display text. availability_rules now means the
 *    PHOTOSHOOT hours; the text is pre-filled from them once, only when nothing is stored yet.
 *
 * down() drops the new columns. The earlier concurrent_sessions value is not restored (it is not kept anywhere).
 */
return new class extends Migration
{
    private const HOLD_INDEX = 'photography_bookings_status_hold_index';

    private const DAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 0 => 'Sun'];

    public function up(): void
    {
        Schema::table('photography_bookings', function (Blueprint $table) {
            $table->dateTime('hold_expires_at')->nullable()->after('proposed_note');
            $table->index(['status', 'hold_expires_at'], self::HOLD_INDEX);
        });

        Schema::table('photography_packages', function (Blueprint $table) {
            $table->unsignedSmallInteger('min_people')->default(1)->after('duration_minutes');
        });

        Schema::table('booking_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('request_hold_hours')->default(48)->after('max_advance_days');
            $table->unsignedSmallInteger('max_sessions_per_day')->nullable()->after('request_hold_hours');
        });

        Schema::table('shop_settings', function (Blueprint $table) {
            $table->string('opening_hours', 200)->nullable()->after('email');
        });

        DB::table('booking_settings')->where('concurrent_sessions', '!=', 1)->update(['concurrent_sessions' => 1]);

        $this->holdExistingRequests();
        $this->prefillOpeningHours();
    }

    public function down(): void
    {
        Schema::table('photography_bookings', function (Blueprint $table) {
            $table->dropIndex(self::HOLD_INDEX);
        });
        Schema::table('photography_bookings', function (Blueprint $table) {
            $table->dropColumn('hold_expires_at');
        });
        Schema::table('photography_packages', function (Blueprint $table) {
            $table->dropColumn('min_people');
        });
        Schema::table('booking_settings', function (Blueprint $table) {
            $table->dropColumn(['request_hold_hours', 'max_sessions_per_day']);
        });
        Schema::table('shop_settings', function (Blueprint $table) {
            $table->dropColumn('opening_hours');
        });
    }

    private function holdExistingRequests(): void
    {
        $now = Carbon::now('UTC');
        $settings = DB::table('booking_settings')->where('id', 1)->first();
        $buffer = (int) ($settings->buffer_minutes ?? config('booking.defaults.buffer_minutes', 15));
        $until = $now->copy()->addHours(48);

        // The time each booking would hold: [start, end] as timestamps
        $window = function (object $row): array {
            $start = Carbon::parse($row->starts_at, 'UTC');
            $minutes = $start->diffInMinutes(Carbon::parse($row->ends_at, 'UTC'));
            $held = $row->status === 'proposed' && $row->proposed_starts_at !== null ? Carbon::parse($row->proposed_starts_at, 'UTC') : $start;

            return [$held->getTimestamp(), $held->getTimestamp() + (int) $minutes * 60];
        };

        $columns = ['id', 'status', 'starts_at', 'ends_at', 'proposed_starts_at'];
        $held = DB::table('photography_bookings')->whereNull('deleted_at')->whereIn('status', ['confirmed', 'completed'])
            ->where('ends_at', '>', $now)->get($columns)->map($window)->all();

        $open = DB::table('photography_bookings')->whereNull('deleted_at')->whereIn('status', ['requested', 'proposed'])
            ->orderBy('id')->get($columns);

        foreach ($open as $row) {
            [$start, $end] = $window($row);

            if ($start <= $now->getTimestamp()) {
                continue;
            }

            foreach ($held as [$heldStart, $heldEnd]) {
                if ($start < $heldEnd + $buffer * 60 && $end > $heldStart - $buffer * 60) {
                    continue 2;
                }
            }

            DB::table('photography_bookings')->where('id', $row->id)->update(['hold_expires_at' => $until]);
            $held[] = [$start, $end];
        }
    }

    /** "Mon-Sat 9:00 AM - 5:00 PM; Sun closed", from the weekly rules as they are now. */
    private function prefillOpeningHours(): void
    {
        $rules = DB::table('availability_rules')->get()->keyBy('weekday');

        if ($rules->isEmpty() || DB::table('shop_settings')->where('id', 1)->whereNotNull('opening_hours')->exists()) {
            return;
        }

        $groups = [];

        foreach (self::DAYS as $weekday => $name) {
            $rule = $rules->get($weekday);
            $hours = $rule !== null && $rule->is_open && $rule->opens_at && $rule->closes_at
                ? Carbon::parse($rule->opens_at)->format('g:i A').' - '.Carbon::parse($rule->closes_at)->format('g:i A')
                : 'closed';
            $last = array_key_last($groups);

            if ($last !== null && $groups[$last]['hours'] === $hours) {
                $groups[$last]['to'] = $name;
            } else {
                $groups[] = ['from' => $name, 'to' => null, 'hours' => $hours];
            }
        }

        $text = mb_substr(implode('; ', array_map(fn (array $group) => $group['from'].($group['to'] === null ? '' : '-'.$group['to']).' '.$group['hours'], $groups)), 0, 200);

        if (! DB::table('shop_settings')->where('id', 1)->exists()) {
            DB::table('shop_settings')->insertOrIgnore(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }

        DB::table('shop_settings')->where('id', 1)->update(['opening_hours' => $text]);
    }
};
