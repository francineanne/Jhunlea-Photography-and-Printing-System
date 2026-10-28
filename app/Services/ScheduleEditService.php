<?php

namespace App\Services;

use App\Models\AvailabilityRule;
use App\Models\BlockedDate;
use App\Models\BookingSetting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Saves the owner's schedule: the weekly opening hours, the booking settings and blocked dates. Nothing is cached, so
 * the booking wizard and the slot list use a change at once. It never touches a booking (ScheduleImpactService shows
 * the owner which bookings a change would leave outside the rules first) and never the schedule lock (lock_version).
 */
class ScheduleEditService
{
    /**
     * One row per weekday, created when missing. A closed day keeps no times.
     *
     * @param  array<int, array{open: bool, opens: ?string, closes: ?string}>  $hours  weekday (0 Sunday) => H:i in shop local time
     */
    public function saveHours(array $hours): void
    {
        DB::transaction(function () use ($hours) {
            foreach ($hours as $weekday => $day) {
                $rule = AvailabilityRule::query()->lockForUpdate()->firstWhere('weekday', $weekday) ?? new AvailabilityRule;
                $rule->weekday = $weekday;
                $rule->fill([
                    'is_open' => $day['open'],
                    'opens_at' => $day['open'] ? $day['opens'].':00' : null,
                    'closes_at' => $day['open'] ? $day['closes'].':00' : null,
                ])->save();
            }
        });
    }

    /**
     * Saves the four settings on the single row (id 1). The row may not exist yet (the config defaults are used until
     * the owner saves): it is created with insertOrIgnore, so two saves at once can never create it twice, and then only
     * the four settings are updated. lock_version is never written here.
     *
     * @param  array{buffer_minutes: int, min_notice_hours: int, max_advance_days: int, concurrent_sessions: int}  $settings
     */
    public function saveSettings(array $settings): void
    {
        DB::transaction(function () use ($settings) {
            DB::table('booking_settings')->insertOrIgnore(['id' => BookingSetting::ROW_ID] + config('booking.defaults') + ['lock_version' => 0, 'created_at' => now(), 'updated_at' => now()]);

            DB::table('booking_settings')->where('id', BookingSetting::ROW_ID)->update([
                'buffer_minutes' => $settings['buffer_minutes'],
                'min_notice_hours' => $settings['min_notice_hours'],
                'max_advance_days' => $settings['max_advance_days'],
                'concurrent_sessions' => $settings['concurrent_sessions'],
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * Blocks every day in the list (one row per day). A day that is already blocked keeps its row and reason.
     *
     * @param  list<string>  $days  Y-m-d in shop local time
     * @return int how many days were newly blocked
     */
    public function blockDays(array $days, ?string $reason, User $owner): int
    {
        $now = Carbon::now();
        $rows = array_map(fn (string $day) => ['date' => $day, 'reason' => $reason, 'created_by' => $owner->getKey(), 'created_at' => $now, 'updated_at' => $now], $days);

        return DB::table('blocked_dates')->insertOrIgnore($rows);
    }

    public function unblock(BlockedDate $blocked): void
    {
        $blocked->delete();
    }
}
