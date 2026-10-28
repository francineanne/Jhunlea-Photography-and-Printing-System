<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\AvailabilityRule;
use App\Models\BlockedDate;
use App\Models\PhotographyBooking;
use App\Support\LocalTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The owner's calendar: a month (weeks from Monday, as in the design), a week or one day, from real data only. Each
 * booking lands on its calendar day in the shop's time zone. Bookings, blocked dates and opening hours are each read
 * with one query for the whole period (plus the customer and package of the bookings), whatever the number of days.
 */
class ScheduleCalendarService
{
    /** Statuses the calendar shows; rejected and cancelled bookings no longer hold anything. */
    public const SHOWN = [BookingStatus::Confirmed, BookingStatus::Completed, BookingStatus::Requested, BookingStatus::Proposed];

    /**
     * @return array{
     *     view: string, date: string, title: string, today: string, previous: string, next: string,
     *     days: list<array{date: string, inPeriod: bool, today: bool, closed: bool, blocked: ?string, bookings: Collection<int, PhotographyBooking>}>
     * }
     */
    public function build(string $view, string $date): array
    {
        $timezone = config('app.display_timezone');
        $anchor = Carbon::createFromFormat('!Y-m-d', $date, $timezone);
        $today = LocalTime::format(Carbon::now('UTC'), 'Y-m-d');

        [$first, $last, $periodFirst, $periodLast, $previous, $next, $title] = match ($view) {
            'day' => [$anchor->copy(), $anchor->copy(), $anchor->copy(), $anchor->copy(), $anchor->copy()->subDay(), $anchor->copy()->addDay(), $anchor->format('l, F j, Y')],
            'week' => [
                $anchor->copy()->startOfWeek(Carbon::MONDAY), $anchor->copy()->endOfWeek(Carbon::SUNDAY)->startOfDay(),
                $anchor->copy()->startOfWeek(Carbon::MONDAY), $anchor->copy()->endOfWeek(Carbon::SUNDAY)->startOfDay(),
                $anchor->copy()->subWeek(), $anchor->copy()->addWeek(),
                'Week of '.$anchor->copy()->startOfWeek(Carbon::MONDAY)->format('M j, Y'),
            ],
            default => [
                $anchor->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY), $anchor->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY)->startOfDay(),
                $anchor->copy()->startOfMonth(), $anchor->copy()->endOfMonth()->startOfDay(),
                $anchor->copy()->startOfMonth()->subMonth(), $anchor->copy()->startOfMonth()->addMonth(),
                $anchor->format('F Y'),
            ],
        };

        $bookings = PhotographyBooking::query()
            ->with(['customer:id,name', 'package'])
            ->whereIn('status', self::SHOWN)
            ->where('starts_at', '>=', LocalTime::dayStartUtc($first->format('Y-m-d')))
            ->where('starts_at', '<=', LocalTime::dayEndUtc($last->format('Y-m-d')))
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (PhotographyBooking $booking) => LocalTime::format($booking->starts_at, 'Y-m-d'));

        $blocked = BlockedDate::query()
            ->whereBetween('date', [$first->format('Y-m-d'), $last->format('Y-m-d')])
            ->get()
            ->mapWithKeys(fn (BlockedDate $day) => [$day->date->format('Y-m-d') => $day->reason ?? 'Blocked']);

        $rules = AvailabilityRule::query()->get()->keyBy('weekday');

        $days = [];

        for ($day = $first->copy(); $day->lte($last); $day->addDay()) {
            $key = $day->format('Y-m-d');
            $rule = $rules->get($day->dayOfWeek);

            $days[] = [
                'date' => $key,
                'inPeriod' => $day->betweenIncluded($periodFirst, $periodLast),
                'today' => $key === $today,
                'closed' => $rule === null || ! $rule->is_open,
                'blocked' => $blocked[$key] ?? null,
                'bookings' => $bookings->get($key, collect()),
            ];
        }

        return [
            'view' => $view,
            'date' => $date,
            'title' => $title,
            'today' => $today,
            'previous' => $previous->format('Y-m-d'),
            'next' => $next->format('Y-m-d'),
            'days' => $days,
        ];
    }
}
