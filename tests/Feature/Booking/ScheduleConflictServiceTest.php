<?php

namespace Tests\Feature\Booking;

use App\Enums\BookingStatus;
use App\Models\PhotographyBooking;
use App\Services\ScheduleConflictService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleConflictServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpSchedule;

    private function service(): ScheduleConflictService
    {
        return app(ScheduleConflictService::class);
    }

    private function booking(string $manilaStart, int $minutes = 60, BookingStatus $status = BookingStatus::Confirmed): PhotographyBooking
    {
        return PhotographyBooking::factory()->status($status)->at($this->manila($manilaStart), $minutes)->create();
    }

    /** @return array{blocking: list<int>, competing: list<int>} */
    private function conflictIds(string $manilaStart, int $minutes = 60, ?int $ignore = null): array
    {
        $start = $this->manila($manilaStart);
        $found = $this->service()->conflictsFor($start, $start->copy()->addMinutes($minutes), $ignore);

        return ['blocking' => $found['blocking']->pluck('id')->all(), 'competing' => $found['competing']->pluck('id')->all()];
    }

    public function test_an_overlapping_confirmed_booking_conflicts(): void
    {
        $this->setUpSchedule();
        $booking = $this->booking('2026-11-03 10:00');

        $this->assertSame([$booking->id], $this->conflictIds('2026-11-03 10:30')['blocking']);
        $this->assertSame([$booking->id], $this->conflictIds('2026-11-03 09:30')['blocking']);
        $this->assertSame([$booking->id], $this->conflictIds('2026-11-03 09:00', 180)['blocking']);
        $this->assertFalse($this->service()->isFree($this->manila('2026-11-03 10:30'), $this->manila('2026-11-03 11:30')));
    }

    public function test_touching_sessions_do_not_conflict_without_a_buffer(): void
    {
        $this->setUpSchedule(buffer: 0);
        $this->booking('2026-11-03 10:00');

        $this->assertSame([], $this->conflictIds('2026-11-03 11:00')['blocking']);
        $this->assertSame([], $this->conflictIds('2026-11-03 09:00')['blocking']);
        $this->assertTrue($this->service()->isFree($this->manila('2026-11-03 11:00'), $this->manila('2026-11-03 12:00')));
    }

    public function test_the_buffer_turns_touching_into_a_conflict(): void
    {
        $this->setUpSchedule(buffer: 15);
        $booking = $this->booking('2026-11-03 10:00');

        $this->assertSame([$booking->id], $this->conflictIds('2026-11-03 11:00')['blocking']);
        $this->assertSame([$booking->id], $this->conflictIds('2026-11-03 11:14', 30)['blocking']);
        $this->assertSame([], $this->conflictIds('2026-11-03 11:15', 30)['blocking']);
        $this->assertSame([], $this->conflictIds('2026-11-03 08:45', 15)['blocking']);
    }

    public function test_a_booking_never_conflicts_with_itself(): void
    {
        $this->setUpSchedule();
        $booking = $this->booking('2026-11-03 10:00');

        $this->assertSame([], $this->conflictIds('2026-11-03 10:00', 60, $booking->id)['blocking']);
        $this->assertTrue($this->service()->isFree($booking->starts_at, $booking->ends_at, $booking->id));
    }

    public function test_requests_and_proposals_come_back_as_competing_not_blocking(): void
    {
        $this->setUpSchedule();
        $requested = $this->booking('2026-11-03 10:00', status: BookingStatus::Requested);
        $proposed = $this->booking('2026-11-03 10:30', status: BookingStatus::Proposed);
        $this->booking('2026-11-03 10:00', status: BookingStatus::Cancelled);
        $this->booking('2026-11-03 10:00', status: BookingStatus::Rejected);

        $found = $this->conflictIds('2026-11-03 10:00');

        $this->assertSame([], $found['blocking']);
        $this->assertEqualsCanonicalizing([$requested->id, $proposed->id], $found['competing']);
        $this->assertTrue($this->service()->isFree($this->manila('2026-11-03 10:00'), $this->manila('2026-11-03 11:00')));
    }

    public function test_concurrent_sessions_count_what_runs_at_the_same_moment(): void
    {
        $this->setUpSchedule(sessions: 2);
        $this->booking('2026-11-03 10:00');

        $this->assertTrue($this->service()->isFree($this->manila('2026-11-03 10:00'), $this->manila('2026-11-03 11:00')));

        $this->booking('2026-11-03 10:30');
        $this->assertFalse($this->service()->isFree($this->manila('2026-11-03 10:30'), $this->manila('2026-11-03 11:00')));
        $this->assertTrue($this->service()->isFree($this->manila('2026-11-03 11:00'), $this->manila('2026-11-03 11:30')));
    }

    public function test_deleted_bookings_do_not_block(): void
    {
        $this->setUpSchedule();
        $this->booking('2026-11-03 10:00')->delete();

        $this->assertSame([], $this->conflictIds('2026-11-03 10:00')['blocking']);
    }
}
