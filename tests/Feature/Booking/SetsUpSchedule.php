<?php

namespace Tests\Feature\Booking;

use App\Models\AvailabilityRule;
use App\Models\BookingSetting;
use Illuminate\Support\Carbon;

/**
 * A known week for booking tests. "Now" is Monday 2 November 2026, 08:00 in Manila (00:00 UTC). The studio opens
 * Monday to Saturday 9:00 to 17:00 Manila time and is closed on Sunday. Settings: buffer, notice, days ahead and
 * sessions at once as given (defaults: 0 buffer, 24 hours notice, 30 days, 1 session), so each test states what it needs.
 */
trait SetsUpSchedule
{
    protected function setUpSchedule(int $buffer = 0, int $noticeHours = 24, int $advanceDays = 30, int $sessions = 1): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 00:00:00', 'UTC'));

        foreach (range(0, 6) as $weekday) {
            $rule = new AvailabilityRule($weekday === 0
                ? ['is_open' => false]
                : ['is_open' => true, 'opens_at' => '09:00:00', 'closes_at' => '17:00:00']);
            $rule->weekday = $weekday;
            $rule->save();
        }

        $this->settings($buffer, $noticeHours, $advanceDays, $sessions);
    }

    protected function settings(int $buffer = 0, int $noticeHours = 24, int $advanceDays = 30, int $sessions = 1): void
    {
        $settings = BookingSetting::query()->find(BookingSetting::ROW_ID) ?? new BookingSetting;
        $settings->fill(['buffer_minutes' => $buffer, 'min_notice_hours' => $noticeHours, 'max_advance_days' => $advanceDays, 'concurrent_sessions' => $sessions]);
        $settings->id = BookingSetting::ROW_ID;
        $settings->save();
    }

    /** A moment written in Manila time, as UTC. */
    protected function manila(string $local): Carbon
    {
        return Carbon::parse($local, 'Asia/Manila')->utc();
    }
}
