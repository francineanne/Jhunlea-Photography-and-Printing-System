<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\BookingSetting;
use App\Models\PhotographyBooking;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Which bookings stand in the way of a session time.
 *
 * Only bookings whose status blocks a slot (BookingStatus::blocksSlot: confirmed and completed) take time. Each one
 * keeps the buffer free before and after it. Two sessions that only touch (one ends exactly when the next starts)
 * do not conflict unless the buffer says so. The studio can run concurrent_sessions sessions at the same moment.
 * Requests and proposals never block; they come back separately as competing requests, a warning for the owner.
 */
class ScheduleConflictService
{
    /**
     * @return array{blocking: Collection<int, PhotographyBooking>, competing: Collection<int, PhotographyBooking>}
     */
    public function conflictsFor(CarbonInterface $start, CarbonInterface $end, ?int $ignoreBookingId = null): array
    {
        $buffer = $this->settings()->buffer_minutes;
        $overlapping = fn (array $statuses) => $this->near($start, $end, $buffer, $ignoreBookingId)
            ->whereIn('status', $statuses)
            ->get()
            ->filter(fn (PhotographyBooking $booking) => self::overlaps($start, $end, $booking->starts_at, $booking->ends_at, $buffer))
            ->values();

        return [
            'blocking' => $overlapping(BookingStatus::blocking()),
            'competing' => $overlapping([BookingStatus::Requested, BookingStatus::Proposed]),
        ];
    }

    /** Whether one more session fits at this time, given the bookings that already hold time and concurrent_sessions. */
    public function isFree(CarbonInterface $start, CarbonInterface $end, ?int $ignoreBookingId = null): bool
    {
        $settings = $this->settings();
        $blocking = $this->conflictsFor($start, $end, $ignoreBookingId)['blocking'];

        return self::mostAtOnce($blocking, $start, $end, $settings->buffer_minutes) < $settings->concurrent_sessions;
    }

    /**
     * Bookings that hold time between two moments (for looking up many slots at once).
     *
     * @return Collection<int, PhotographyBooking>
     */
    public function blockingBetween(CarbonInterface $from, CarbonInterface $to, ?int $ignoreBookingId = null): Collection
    {
        return $this->near($from, $to, $this->settings()->buffer_minutes, $ignoreBookingId)
            ->whereIn('status', BookingStatus::blocking())
            ->get();
    }

    /** Whether session [aStart, aEnd) overlaps session [bStart, bEnd) once the buffer is kept free around the second. */
    public static function overlaps(CarbonInterface $aStart, CarbonInterface $aEnd, CarbonInterface $bStart, CarbonInterface $bEnd, int $bufferMinutes): bool
    {
        return $aStart->lt($bEnd->copy()->addMinutes($bufferMinutes)) && $aEnd->gt($bStart->copy()->subMinutes($bufferMinutes));
    }

    /**
     * The largest number of the given bookings (each widened by the buffer) that run at the same moment inside
     * [start, end). Two bookings that both touch the time but never at the same moment count as one.
     *
     * @param  Collection<int, PhotographyBooking>  $bookings
     */
    public static function mostAtOnce(Collection $bookings, CarbonInterface $start, CarbonInterface $end, int $bufferMinutes): int
    {
        $events = [];

        foreach ($bookings as $booking) {
            if (! self::overlaps($start, $end, $booking->starts_at, $booking->ends_at, $bufferMinutes)) {
                continue;
            }

            $events[] = [$booking->starts_at->copy()->subMinutes($bufferMinutes)->getTimestamp(), 1];
            $events[] = [$booking->ends_at->copy()->addMinutes($bufferMinutes)->getTimestamp(), -1];
        }

        // At the same moment, an end comes before a start, so back-to-back bookings never count twice
        usort($events, fn (array $a, array $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);

        $now = 0;
        $most = 0;

        foreach ($events as [, $change]) {
            $now += $change;
            $most = max($most, $now);
        }

        return $most;
    }

    public function settings(): BookingSetting
    {
        return BookingSetting::current();
    }

    /** @return Builder<PhotographyBooking> */
    private function near(CarbonInterface $start, CarbonInterface $end, int $bufferMinutes, ?int $ignoreBookingId)
    {
        return PhotographyBooking::query()
            ->where('starts_at', '<', $end->copy()->utc()->addMinutes($bufferMinutes))
            ->where('ends_at', '>', $start->copy()->utc()->subMinutes($bufferMinutes))
            ->when($ignoreBookingId, fn ($query) => $query->whereKeyNot($ignoreBookingId));
    }
}
