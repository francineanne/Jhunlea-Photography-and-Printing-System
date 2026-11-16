<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\PhotographyBooking;
use App\Support\LocalTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Which future bookings a schedule change would leave outside the studio's rules, BEFORE it is saved, so the owner can
 * decide. It only reads: no booking is ever cancelled or moved because of a schedule change. Bookings that HOLD time
 * (PhotographyBooking::holdsTime, Phase 10A: confirmed sessions, and requests and proposals whose hold has not run
 * out) are what the owner must acknowledge (the "confirmed" list, named so since Phase 4D); requests and proposals
 * that hold nothing are listed as information only. Each booking is looked at where it holds or asks for time:
 * heldStart() to heldEnd(), the proposed time for a proposal.
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
            $local = LocalTime::in($booking->heldStart());
            $day = $hours[$local->dayOfWeek] ?? null;

            if ($day === null || ! $day['open']) {
                return true;
            }

            $opens = Carbon::parse($local->format('Y-m-d').' '.$day['opens'], config('app.display_timezone'));
            $closes = Carbon::parse($local->format('Y-m-d').' '.$day['closes'], config('app.display_timezone'));

            return $booking->heldStart()->lt($opens) || $booking->heldEnd()->gt($closes);
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

        return $this->split(fn (PhotographyBooking $booking) => isset($days[LocalTime::format($booking->heldStart(), 'Y-m-d')]));
    }

    /**
     * A new buffer or a new daily limit: bookings that hold time and would then overlap another one (the buffer), or
     * sit on a day with more photoshoots than the new limit allows (null = no limit).
     *
     * @return array{confirmed: Collection<int, PhotographyBooking>, pending: Collection<int, PhotographyBooking>}
     */
    public function forSettings(int $bufferMinutes, ?int $dailyLimit = null): array
    {
        $holding = $this->upcoming()->filter(fn (PhotographyBooking $booking) => $booking->holdsTime())->values();
        $perDay = $holding->countBy(fn (PhotographyBooking $booking) => LocalTime::format($booking->heldStart(), 'Y-m-d'));

        $affected = $holding->filter(fn (PhotographyBooking $booking) => ScheduleConflictService::mostAtOnce($holding, $booking->heldStart(), $booking->heldEnd(), $bufferMinutes) > 1
            || ($dailyLimit !== null && $perDay[LocalTime::format($booking->heldStart(), 'Y-m-d')] > $dailyLimit))->values();

        return ['confirmed' => $affected, 'pending' => collect()];
    }

    /**
     * @param  callable(PhotographyBooking): bool  $outside
     * @return array{confirmed: Collection<int, PhotographyBooking>, pending: Collection<int, PhotographyBooking>}
     */
    private function split(callable $outside): array
    {
        $affected = $this->upcoming()->filter($outside);

        return [
            'confirmed' => $affected->filter(fn (PhotographyBooking $booking) => $booking->holdsTime())->values(),
            'pending' => $affected->reject(fn (PhotographyBooking $booking) => $booking->holdsTime())->values(),
        ];
    }

    /**
     * Confirmed bookings, requests and proposals whose time (the proposed time for a proposal) is still ahead, oldest
     * first, read once. A request whose time has passed (expired) is never listed.
     *
     * @return Collection<int, PhotographyBooking>
     */
    private function upcoming(): Collection
    {
        $now = Carbon::now('UTC');

        return PhotographyBooking::query()
            ->with('customer:id,name')
            ->whereIn('status', [BookingStatus::Confirmed, BookingStatus::Requested, BookingStatus::Proposed])
            ->where(fn ($query) => $query->where('starts_at', '>', $now)->orWhere('proposed_starts_at', '>', $now))
            ->orderBy('starts_at')
            ->get()
            ->filter(fn (PhotographyBooking $booking) => $booking->heldStart()->gt($now))
            ->sortBy(fn (PhotographyBooking $booking) => [$booking->heldStart()->getTimestamp(), $booking->id])
            ->values();
    }
}
