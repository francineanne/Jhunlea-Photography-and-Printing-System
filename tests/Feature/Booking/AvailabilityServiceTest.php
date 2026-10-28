<?php

namespace Tests\Feature\Booking;

use App\Enums\BookingStatus;
use App\Models\AvailabilityRule;
use App\Models\BlockedDate;
use App\Models\BookingSetting;
use App\Models\PhotographyBooking;
use App\Services\AvailabilityService;
use App\Support\Slot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

class AvailabilityServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpSchedule;

    private function service(): AvailabilityService
    {
        return app(AvailabilityService::class);
    }

    /** @return list<string> start times of the slots, in Manila time, like "09:00" */
    private function times(int $minutes, string $date): array
    {
        return $this->service()->slots($minutes, $date, $date)
            ->map(fn (Slot $slot) => $slot->startsAt->copy()->setTimezone('Asia/Manila')->format('H:i'))
            ->all();
    }

    private function confirmedAt(string $manilaStart, int $minutes = 60, BookingStatus $status = BookingStatus::Confirmed): PhotographyBooking
    {
        return PhotographyBooking::factory()->status($status)->at($this->manila($manilaStart), $minutes)->create();
    }

    public function test_a_normal_day_gives_every_half_hour_that_fits_before_closing(): void
    {
        $this->setUpSchedule();

        // Tuesday: 9:00 to 16:00 for a 60-minute session (16:00 + 60 = 17:00, closing)
        $this->assertSame(
            ['09:00', '09:30', '10:00', '10:30', '11:00', '11:30', '12:00', '12:30', '13:00', '13:30', '14:00', '14:30', '15:00', '15:30', '16:00'],
            $this->times(60, '2026-11-03'),
        );
    }

    public function test_slots_are_utc_moments_with_manila_text(): void
    {
        $this->setUpSchedule();

        $first = $this->service()->slots(60, '2026-11-03', '2026-11-03')->first();

        $this->assertSame('2026-11-03 01:00:00', $first->startsAt->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $first->startsAt->getTimezone()->getName());
        $this->assertSame('9:00 AM – 10:00 AM', $first->timeText());
        $this->assertSame('Tue, Nov 3, 2026', $first->dateText());
    }

    public function test_a_closed_weekday_has_no_slots(): void
    {
        $this->setUpSchedule();

        $this->assertSame([], $this->times(60, '2026-11-08')); // Sunday
    }

    public function test_a_weekday_without_a_rule_is_closed(): void
    {
        $this->setUpSchedule();
        AvailabilityRule::query()->where('weekday', 3)->delete();

        $this->assertSame([], $this->times(60, '2026-11-04')); // Wednesday
    }

    public function test_a_blocked_date_has_no_slots(): void
    {
        $this->setUpSchedule();
        BlockedDate::factory()->create(['date' => '2026-11-03', 'reason' => 'Holiday']);

        $this->assertSame([], $this->times(60, '2026-11-03'));
        $this->assertNotEmpty($this->times(60, '2026-11-04'));
    }

    public function test_a_slot_ending_exactly_at_closing_is_offered_and_one_running_past_is_not(): void
    {
        $this->setUpSchedule();

        $times = $this->times(90, '2026-11-03');

        $this->assertSame('15:30', end($times)); // 15:30 + 90 = 17:00
        $this->assertNotContains('16:00', $times);
    }

    public function test_a_package_longer_than_the_opening_day_has_no_slots(): void
    {
        $this->setUpSchedule();

        $this->assertSame([], $this->times(9 * 60, '2026-11-03'));
        $this->assertSame(['09:00'], $this->times(8 * 60, '2026-11-03'));
    }

    public function test_the_minimum_notice_boundary_is_exact_to_the_minute(): void
    {
        $this->setUpSchedule(noticeHours: 24);

        // Now 08:00 Monday; Tuesday 9:00 is 25 hours away. Move now so 9:00 Tuesday is exactly 24 hours away
        $this->travelTo($this->manila('2026-11-02 09:00'));
        $this->assertContains('09:00', $this->times(60, '2026-11-03'));

        $this->travelTo($this->manila('2026-11-02 09:01'));
        $this->assertNotContains('09:00', $this->times(60, '2026-11-03'));
        $this->assertContains('09:30', $this->times(60, '2026-11-03'));

        $this->travelTo($this->manila('2026-11-02 08:59'));
        $this->assertContains('09:00', $this->times(60, '2026-11-03'));
    }

    public function test_the_maximum_advance_boundary_is_exact_to_the_minute(): void
    {
        $this->setUpSchedule(advanceDays: 10);

        // Now is Monday 08:00 Manila; ten days ahead is Thursday 12 November 08:00 Manila
        $this->assertSame([], $this->times(60, '2026-11-12'));

        $this->travelTo($this->manila('2026-11-02 09:00'));
        $this->assertSame(['09:00'], $this->times(60, '2026-11-12'));

        $this->travelTo($this->manila('2026-11-02 09:01'));
        $this->assertSame(['09:00'], $this->times(60, '2026-11-12'));

        $this->travelTo($this->manila('2026-11-02 08:59'));
        $this->assertSame([], $this->times(60, '2026-11-12'));
    }

    public function test_the_past_is_never_offered(): void
    {
        $this->setUpSchedule(noticeHours: 0);

        $this->assertSame([], $this->times(60, '2026-10-30'));
        // Today: now is 08:00, so the day starts at 9:00 and nothing earlier
        $this->assertSame('09:00', $this->times(60, '2026-11-02')[0]);

        $this->travelTo($this->manila('2026-11-02 10:10'));
        $this->assertSame('10:30', $this->times(60, '2026-11-02')[0]);
    }

    public function test_a_confirmed_booking_blocks_its_time(): void
    {
        $this->setUpSchedule();
        $this->confirmedAt('2026-11-03 10:00');

        $times = $this->times(60, '2026-11-03');

        $this->assertNotContains('09:30', $times);
        $this->assertNotContains('10:00', $times);
        $this->assertNotContains('10:30', $times);
        $this->assertContains('09:00', $times); // ends 10:00, touches only
        $this->assertContains('11:00', $times); // starts at 11:00, touches only
    }

    public function test_the_buffer_keeps_time_free_around_a_confirmed_booking(): void
    {
        $this->setUpSchedule(buffer: 15);
        $this->confirmedAt('2026-11-03 10:00');

        $times = $this->times(60, '2026-11-03');

        $this->assertNotContains('09:00', $times); // would end at 10:00, inside the 15 minutes before
        $this->assertNotContains('11:00', $times); // would start at 11:00, inside the 15 minutes after
        $this->assertContains('11:30', $times);
    }

    public function test_requested_and_proposed_bookings_do_not_block(): void
    {
        $this->setUpSchedule();
        $this->confirmedAt('2026-11-03 10:00', status: BookingStatus::Requested);
        $this->confirmedAt('2026-11-03 13:00', status: BookingStatus::Proposed);

        $times = $this->times(60, '2026-11-03');

        $this->assertContains('10:00', $times);
        $this->assertContains('13:00', $times);
    }

    public function test_rejected_and_cancelled_bookings_do_not_block_but_completed_ones_do(): void
    {
        $this->setUpSchedule(noticeHours: 0);
        $this->confirmedAt('2026-11-03 10:00', status: BookingStatus::Cancelled);
        $this->confirmedAt('2026-11-03 12:00', status: BookingStatus::Rejected);
        $this->confirmedAt('2026-11-03 14:00', status: BookingStatus::Completed);

        $times = $this->times(60, '2026-11-03');

        $this->assertContains('10:00', $times);
        $this->assertContains('12:00', $times);
        $this->assertNotContains('14:00', $times);
    }

    public function test_two_sessions_at_once_need_two_bookings_to_block(): void
    {
        $this->setUpSchedule(sessions: 2);
        $this->confirmedAt('2026-11-03 10:00');

        $this->assertContains('10:00', $this->times(60, '2026-11-03'));

        $this->confirmedAt('2026-11-03 10:00');
        $this->assertNotContains('10:00', $this->times(60, '2026-11-03'));
    }

    public function test_two_bookings_that_never_run_together_count_as_one(): void
    {
        $this->setUpSchedule(sessions: 2);
        $this->confirmedAt('2026-11-03 09:30', 45);  // 9:30 to 10:15
        $this->confirmedAt('2026-11-03 10:30', 45);  // 10:30 to 11:15

        // A 10:00 to 11:00 session touches both, but at no moment do all three run: there is room
        $this->assertContains('10:00', $this->times(60, '2026-11-03'));
    }

    public function test_manila_mornings_are_the_previous_day_in_utc(): void
    {
        $this->setUpSchedule(noticeHours: 0);
        // A confirmed booking from 7:00 to 8:30 UTC is 15:00 to 16:30 in Manila: same Manila day, later in UTC
        PhotographyBooking::factory()->status(BookingStatus::Confirmed)->at(Carbon::parse('2026-11-03 07:00', 'UTC'), 90)->create();

        $times = $this->times(60, '2026-11-03');

        $this->assertNotContains('15:00', $times);
        $this->assertNotContains('16:00', $times);
        $this->assertContains('14:00', $times);
    }

    public function test_a_session_crossing_midnight_utc_stays_on_one_manila_day(): void
    {
        $this->setUpSchedule(noticeHours: 0);
        // 07:30 to 08:30 Manila on Wednesday is 23:30 Tuesday to 00:30 Wednesday in UTC. Open early that day
        $rule = AvailabilityRule::query()->where('weekday', 3)->first();
        $rule->opens_at = '07:30:00';
        $rule->save();

        $slots = $this->service()->slots(60, '2026-11-04', '2026-11-04');
        $first = $slots->first();

        $this->assertSame('2026-11-03 23:30:00', $first->startsAt->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-04 00:30:00', $first->endsAt->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-04', $first->localDate());

        // A confirmed booking on it blocks it, even though its UTC date is the day before
        PhotographyBooking::factory()->status(BookingStatus::Confirmed)->at(Carbon::parse('2026-11-03 23:30', 'UTC'), 60)->create();
        $this->assertNotContains('07:30', $this->times(60, '2026-11-04'));
    }

    public function test_the_local_day_edges_map_to_the_right_utc_hours(): void
    {
        $this->setUpSchedule(noticeHours: 0);
        foreach (AvailabilityRule::all() as $rule) {
            $rule->forceFill(['is_open' => true, 'opens_at' => '00:00:00', 'closes_at' => '23:59:00'])->save();
        }

        $slots = $this->service()->slots(30, '2026-11-05', '2026-11-05');

        // Midnight Manila is 16:00 UTC the day before; the last 30-minute slot is 23:00 Manila, 15:00 UTC
        $this->assertSame('2026-11-04 16:00:00', $slots->first()->startsAt->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-05 15:00:00', $slots->last()->startsAt->format('Y-m-d H:i:s'));
        $this->assertTrue($slots->every(fn (Slot $slot) => $slot->localDate() === '2026-11-05'));
    }

    public function test_is_available_matches_the_slots_and_can_leave_one_booking_out(): void
    {
        $this->setUpSchedule();
        $booking = $this->confirmedAt('2026-11-03 10:00');

        $this->assertTrue($this->service()->isAvailable(60, $this->manila('2026-11-03 09:00')));
        $this->assertFalse($this->service()->isAvailable(60, $this->manila('2026-11-03 10:00')));
        $this->assertFalse($this->service()->isAvailable(60, $this->manila('2026-11-03 09:10')), 'not on the half hour');
        $this->assertFalse($this->service()->isAvailable(60, $this->manila('2026-11-08 10:00')), 'Sunday');
        $this->assertTrue($this->service()->isAvailable(60, $this->manila('2026-11-03 10:00'), $booking->id));
    }

    public function test_the_lookup_range_is_limited(): void
    {
        $this->setUpSchedule();

        $this->expectException(InvalidArgumentException::class);

        $this->service()->slots(60, '2026-11-03', '2027-03-03');
    }

    public function test_a_backwards_range_is_refused(): void
    {
        $this->setUpSchedule();

        $this->expectException(InvalidArgumentException::class);

        $this->service()->slots(60, '2026-11-05', '2026-11-03');
    }

    public function test_the_config_defaults_are_used_until_the_owner_saves_settings(): void
    {
        $this->setUpSchedule();
        BookingSetting::query()->delete();

        // Defaults: 24 hours notice, so Monday has nothing and Tuesday starts at 9:00
        $this->assertSame([], $this->times(60, '2026-11-02'));
        $this->assertSame('09:00', $this->times(60, '2026-11-03')[0]);
    }
}
