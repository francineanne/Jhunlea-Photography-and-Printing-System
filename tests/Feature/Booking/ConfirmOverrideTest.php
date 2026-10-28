<?php

namespace Tests\Feature\Booking;

use App\Enums\BookingStatus as Status;
use App\Exceptions\BookingTransitionException;
use App\Models\AvailabilityRule;
use App\Models\BlockedDate;
use App\Models\BookingSetting;
use App\Models\BookingStatusHistory;
use App\Models\PhotographyBooking;
use App\Models\User;
use App\Services\AvailabilityService;
use App\Services\BookingTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Phase 4C: schedule rules the studio may set aside when it confirms, and conflicts it never may. */
class ConfirmOverrideTest extends TestCase
{
    use RefreshDatabase, SetsUpSchedule;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSchedule();
        $this->owner = User::factory()->owner()->create();
    }

    private function rules(string $manila, int $minutes = 60): array
    {
        return app(AvailabilityService::class)->ruleViolations($minutes, $this->manila($manila));
    }

    private function booking(string $manila, Status $status = Status::Requested): PhotographyBooking
    {
        return PhotographyBooking::factory()->status($status)->at($this->manila($manila))->create();
    }

    public function test_a_time_inside_every_rule_breaks_none(): void
    {
        $this->assertSame([], $this->rules('2026-11-05 10:00'));
        $this->assertSame([], $this->rules('2026-11-05 16:00'));
    }

    public function test_each_rule_has_its_own_plain_reason(): void
    {
        $this->assertSame(['The studio is closed on Sundays.'], $this->rules('2026-11-08 10:00'));
        $this->assertSame(['It is outside the opening hours (9:00 AM to 5:00 PM).'], $this->rules('2026-11-05 16:30'));
        $this->assertSame(['It is outside the opening hours (9:00 AM to 5:00 PM).'], $this->rules('2026-11-05 08:00'));
        $this->assertSame(['It is less than 24 hours from now (the minimum notice).'], $this->rules('2026-11-02 15:00'));
        // 7:00 this morning: before opening, and already over
        $this->assertSame(['It is outside the opening hours (9:00 AM to 5:00 PM).', 'The time has already passed.'], $this->rules('2026-11-02 07:00', 30));
        $this->assertSame(['It is more than 30 days ahead (how far ahead one may book).'], $this->rules('2026-12-03 10:00'));

        BlockedDate::factory()->create(['date' => '2026-11-05', 'reason' => 'Studio repairs']);
        $this->assertSame(['The studio blocked Thu, Nov 5, 2026 (Studio repairs).'], $this->rules('2026-11-05 10:00'));
    }

    public function test_several_rules_are_listed_together(): void
    {
        $reasons = $this->rules('2026-12-06 18:00');

        $this->assertContains('The studio is closed on Sundays.', $reasons);
        $this->assertContains('It is more than 30 days ahead (how far ahead one may book).', $reasons);
    }

    public function test_confirming_a_time_that_now_breaks_a_rule_needs_the_override(): void
    {
        $booking = $this->booking('2026-11-05 10:00');
        BlockedDate::factory()->create(['date' => '2026-11-05', 'reason' => 'Studio repairs']);

        try {
            app(BookingTransitionService::class)->confirm($booking, $this->owner);
            $this->fail('Confirming outside the schedule must need the override.');
        } catch (BookingTransitionException $e) {
            $this->assertStringContainsString('The studio blocked Thu, Nov 5, 2026 (Studio repairs).', $e->getMessage());
            $this->assertStringContainsString('Confirm anyway', $e->getMessage());
        }

        $this->assertSame(Status::Requested, $booking->fresh()->status);
        $this->assertSame(0, BookingStatusHistory::count());

        app(BookingTransitionService::class)->confirm($booking, $this->owner, null, 'Opening just for them.', outsideSchedule: true);

        $this->assertSame(Status::Confirmed, $booking->fresh()->status);
        $this->assertSame(
            'Confirmed outside the usual schedule: The studio blocked Thu, Nov 5, 2026 (Studio repairs). Opening just for them.',
            BookingStatusHistory::sole()->note,
        );
    }

    public function test_changed_hours_after_the_request_also_need_the_override(): void
    {
        $booking = $this->booking('2026-11-06 15:00');
        AvailabilityRule::query()->where('weekday', 5)->update(['closes_at' => '13:00:00']);

        $this->expectException(BookingTransitionException::class);
        app(BookingTransitionService::class)->confirm($booking, $this->owner);
    }

    public function test_the_override_never_sets_aside_a_conflict(): void
    {
        $this->booking('2026-11-05 10:00', Status::Confirmed);
        $booking = $this->booking('2026-11-05 10:30');
        BlockedDate::factory()->create(['date' => '2026-11-05']);

        try {
            app(BookingTransitionService::class)->confirm($booking, $this->owner, null, null, outsideSchedule: true);
            $this->fail('A conflict must refuse even with the override.');
        } catch (BookingTransitionException $e) {
            $this->assertSame(BookingTransitionException::conflict()->getMessage(), $e->getMessage());
        }

        $this->assertSame(Status::Requested, $booking->fresh()->status);
        $this->assertSame(0, BookingStatusHistory::count());
    }

    public function test_a_time_inside_the_rules_needs_no_override_and_writes_the_plain_note(): void
    {
        $booking = $this->booking('2026-11-05 10:00');

        app(BookingTransitionService::class)->confirm($booking, $this->owner, null, 'See you then.', outsideSchedule: true);

        $this->assertSame('See you then.', BookingStatusHistory::sole()->note);
    }

    public function test_the_override_still_takes_the_schedule_lock(): void
    {
        $booking = $this->booking('2026-11-05 10:00');
        BlockedDate::factory()->create(['date' => '2026-11-05']);
        $before = (int) BookingSetting::query()->value('lock_version');

        app(BookingTransitionService::class)->confirm($booking, $this->owner, null, null, outsideSchedule: true);

        $this->assertSame($before + 1, (int) BookingSetting::query()->value('lock_version'));
    }
}
