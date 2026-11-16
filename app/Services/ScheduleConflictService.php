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
 * A booking takes time when PhotographyBooking::scopeHoldingTime() says so (Phase 10A, the ONE holding rule: confirmed
 * and completed sessions, and requests and proposals whose hold has not run out). The time it takes is
 * heldStart() to heldEnd() (the proposed time for a proposal), with the buffer kept free before and after. Two sessions
 * that only touch (one ends exactly when the next starts) do not conflict unless the buffer says so. The shop has one
 * studio, so one session runs at a time. Requests and proposals that do NOT hold time come back separately as
 * competing requests, a warning for the owner.
 */
class ScheduleConflictService
{
    /**
     * @return array{blocking: Collection<int, PhotographyBooking>, competing: Collection<int, PhotographyBooking>}
     */
    public function conflictsFor(CarbonInterface $start, CarbonInterface $end, ?int $ignoreBookingId = null): array
    {
        $buffer = $this->settings()->buffer_minutes;
        $overlapping = fn (Builder $query) => $query->get()
            ->filter(fn (PhotographyBooking $booking) => self::overlaps($start, $end, $booking->heldStart(), $booking->heldEnd(), $buffer))
            ->values();

        $blocking = $overlapping($this->near($start, $end, $buffer, $ignoreBookingId)->holdingTime());

        return [
            'blocking' => $blocking,
            'competing' => $overlapping($this->near($start, $end, $buffer, $ignoreBookingId)->waiting())
                ->reject(fn (PhotographyBooking $booking) => $blocking->contains('id', $booking->id))
                ->values(),
        ];
    }

    /** Whether one more session fits at this time, given the bookings that already hold time. */
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
        return $this->near($from, $to, $this->settings()->buffer_minutes, $ignoreBookingId)->holdingTime()->get();
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
            if (! self::overlaps($start, $end, $booking->heldStart(), $booking->heldEnd(), $bufferMinutes)) {
                continue;
            }

            $events[] = [$booking->heldStart()->subMinutes($bufferMinutes)->getTimestamp(), 1];
            $events[] = [$booking->heldEnd()->addMinutes($bufferMinutes)->getTimestamp(), -1];
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

    /**
     * Bookings whose time could touch [start, end] with the buffer: by the session time, or for a proposal by its
     * proposed start (a day of margin, since the end of a proposal is worked out in PHP; callers filter exactly).
     *
     * @return Builder<PhotographyBooking>
     */
    private function near(CarbonInterface $start, CarbonInterface $end, int $bufferMinutes, ?int $ignoreBookingId): Builder
    {
        $before = $end->copy()->utc()->addMinutes($bufferMinutes);
        $after = $start->copy()->utc()->subMinutes($bufferMinutes);

        return PhotographyBooking::query()
            ->where(fn (Builder $time) => $time
                ->where(fn (Builder $session) => $session
                    ->where(fn (Builder $which) => $which->where('status', '!=', BookingStatus::Proposed)->orWhereNull('proposed_starts_at'))
                    ->where('starts_at', '<', $before)->where('ends_at', '>', $after))
                ->orWhere(fn (Builder $proposal) => $proposal->where('status', BookingStatus::Proposed)
                    ->where('proposed_starts_at', '<', $before)->where('proposed_starts_at', '>', $after->copy()->subDay())))
            ->when($ignoreBookingId, fn ($query) => $query->whereKeyNot($ignoreBookingId));
    }
}
