<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\PhotographyBooking;
use App\Support\LocalTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Which future bookings a schedule change would leave outside the studio's rules, BEFORE it is saved, so the owner can
 * decide. It only reads: no booking is ever cancelled or moved because of a schedule change. Confirmed sessions are
 * what the owner must acknowledge; pending requests and proposals are listed as information only.
 */
class ScheduleImpactService
{
    /**
     * New opening hours: bookings on a weekday that would be closed, or starting before opening or ending after closing.
     *
     * @param  array<int, array{open: bool, opens: ?string, closes: ?string}>  $hours  weekday (0 Sunday) => H:i in shop local time
     * @return array{confirmed: Collection<int, PhotographyBooking>, pending: Collection<int, PhotographyBooking>}
     */
    public function forHours(array $hours): array
    {
        return $this->split(function (PhotographyBooking $booking) use ($hours) {
            $local = LocalTime::in($booking->starts_at);
            $day = $hours[$local->dayOfWeek] ?? null;

            if ($day === null || ! $day['open']) {
                return true;
            }

            $opens = Carbon::parse($local->format('Y-m-d').' '.$day['opens'], config('app.display_timezone'));
            $closes = Carbon::parse($local->format('Y-m-d').' '.$day['closes'], config('app.display_timezone'));

            return $booking->starts_at->lt($opens) || $booking->ends_at->gt($closes);
        });
    }

    /**
     * New blocked days: bookings on any of them (shop local calendar days).
     *
     * @param  list<string>  $days  Y-m-d
     * @return array{confirmed: Collection<int, PhotographyBooking>, pending: Collection<int, PhotographyBooking>}
     */
    public function forBlockedDays(array $days): array
    {
        $days = array_flip($days);

        return $this->split(fn (PhotographyBooking $booking) => isset($days[LocalTime::format($booking->starts_at, 'Y-m-d')]));
    }

    /**
     * New buffer and sessions at the same time: confirmed sessions that would then overlap more than the studio can run.
     * Requests never hold time, so only confirmed sessions are checked.
     *
     * @return array{confirmed: Collection<int, PhotographyBooking>, pending: Collection<int, PhotographyBooking>}
     */
    public function forSettings(int $bufferMinutes, int $concurrentSessions): array
    {
        $confirmed = $this->upcoming([BookingStatus::Confirmed]);

        $clashing = $confirmed->filter(fn (PhotographyBooking $booking) => ScheduleConflictService::mostAtOnce(
            $confirmed,
            $booking->starts_at,
            $booking->ends_at,
            $bufferMinutes,
        ) > $concurrentSessions)->values();

        return ['confirmed' => $clashing, 'pending' => collect()];
    }

    /**
     * @param  callable(PhotographyBooking): bool  $outside
     * @return array{confirmed: Collection<int, PhotographyBooking>, pending: Collection<int, PhotographyBooking>}
     */
    private function split(callable $outside): array
    {
        $affected = $this->upcoming([BookingStatus::Confirmed, BookingStatus::Requested, BookingStatus::Proposed])->filter($outside);

        return [
            'confirmed' => $affected->filter(fn (PhotographyBooking $booking) => $booking->status === BookingStatus::Confirmed)->values(),
            'pending' => $affected->reject(fn (PhotographyBooking $booking) => $booking->status === BookingStatus::Confirmed)->values(),
        ];
    }

    /**
     * Bookings in these statuses that have not started yet, oldest first, read once.
     *
     * @param  list<BookingStatus>  $statuses
     * @return Collection<int, PhotographyBooking>
     */
    private function upcoming(array $statuses): Collection
    {
        return PhotographyBooking::query()
            ->with('customer:id,name')
            ->whereIn('status', $statuses)
            ->where('starts_at', '>', Carbon::now('UTC'))
            ->orderBy('starts_at')
            ->get();
    }
}
