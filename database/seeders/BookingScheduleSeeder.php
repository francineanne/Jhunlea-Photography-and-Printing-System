<?php

namespace Database\Seeders;

use App\Models\AvailabilityRule;
use App\Models\BookingSetting;
use Illuminate\Database\Seeder;

/**
 * SAMPLE DATA — a starting schedule for development, to be replaced by the owner's real hours (Phase 4D).
 *
 * Monday to Saturday 9:00 to 17:00, Sunday closed; settings from config/booking.php (15-minute buffer, 24 hours notice,
 * 30 days ahead, one session at a time). Safe to run more than once: a weekday or the settings row that already exists
 * is left alone, so the owner's changes are never overwritten. Creates no bookings and no users.
 */
class BookingScheduleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (range(0, 6) as $weekday) {
            if (AvailabilityRule::query()->where('weekday', $weekday)->exists()) {
                continue;
            }

            $rule = new AvailabilityRule($weekday === 0
                ? ['is_open' => false, 'opens_at' => null, 'closes_at' => null]
                : ['is_open' => true, 'opens_at' => '09:00:00', 'closes_at' => '17:00:00']);
            $rule->weekday = $weekday;
            $rule->save();
        }

        if (! BookingSetting::query()->whereKey(BookingSetting::ROW_ID)->exists()) {
            $settings = new BookingSetting(config('booking.defaults'));
            $settings->id = BookingSetting::ROW_ID;
            $settings->save();
        }
    }
}
