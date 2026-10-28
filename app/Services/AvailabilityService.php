<?php

namespace App\Services;

use App\Models\AvailabilityRule;
use App\Models\BlockedDate;
use App\Models\BookingSetting;
use App\Support\LocalTime;
use App\Support\Slot;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * The free session times for a package length, worked out from the studio's rules:
 * the weekly opening hours (availability_rules) and blocked dates, read in shop local time (config
 * app.display_timezone); the minimum notice and how far ahead one may book (booking_settings); slots every
 * config booking.slot_step_minutes from opening; a session must fit entirely inside the opening hours; and the
 * bookings that already hold time, with the buffer and concurrent_sessions (ScheduleConflictService).
 * Never returns a time in the past. Requests that are not confirmed never take a slot.
 */
class AvailabilityService
{
    public function __construct(private readonly ScheduleConflictService $conflicts) {}

    /**
     * Free slots for a session of this length, from one local calendar day to another (both included).
     *
     * @param  string  $fromDate  Y-m-d in shop local time
     * @param  string  $toDate  Y-m-d in shop local time
     * @return Collection<int, Slot>
     *
     * @throws InvalidArgumentException when the range is backwards or longer than config booking.max_lookup_days
     */
    public function slots(int $durationMinutes, string $fromDate, string $toDate, ?int $ignoreBookingId = null): Collection
    {
        $timezone = config('app.display_timezone');
        $from = Carbon::createFromFormat('!Y-m-d', $fromDate, $timezone);
        $to = Carbon::createFromFormat('!Y-m-d', $toDate, $timezone);

        if ($durationMinutes < 1 || $to->lt($from) || $from->diffInDays($to) >= config('booking.max_lookup_days')) {
            throw new InvalidArgumentException('That range of dates cannot be looked up.');
        }

        $settings = BookingSetting::current();
        [$earliest, $latest] = $this->window($settings);
        $step = (int) config('booking.slot_step_minutes');

        $rules = AvailabilityRule::query()->get()->keyBy('weekday');
        $blocked = BlockedDate::query()
            ->whereBetween('date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->pluck('date')
            ->map(fn ($date) => $date instanceof CarbonInterface ? $date->format('Y-m-d') : (string) $date)
            ->flip();

        // Every booking that could touch these days, read once
        $taken = $this->conflicts->blockingBetween($from->copy()->startOfDay()->utc(), $to->copy()->endOfDay()->utc(), $ignoreBookingId);

        $slots = collect();

        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $rule = $rules->get($day->dayOfWeek);

            if ($rule === null || ! $rule->is_open || ! $rule->opens_at || ! $rule->closes_at || $blocked->has($day->format('Y-m-d'))) {
                continue;
            }

            $opens = Carbon::parse($day->format('Y-m-d').' '.$rule->opens_at, $timezone);
            $closes = Carbon::parse($day->format('Y-m-d').' '.$rule->closes_at, $timezone);

            for ($cursor = $opens->copy(); $cursor->copy()->addMinutes($durationMinutes)->lte($closes); $cursor->addMinutes($step)) {
                $start = $cursor->copy()->utc();
                $end = $start->copy()->addMinutes($durationMinutes);

                if ($start->lt($earliest)) {
                    continue;
                }

                if ($start->gt($latest)) {
                    break 2;
                }

                if (ScheduleConflictService::mostAtOnce($taken, $start, $end, $settings->buffer_minutes) < $settings->concurrent_sessions) {
                    $slots->push(new Slot($start, $end));
                }
            }
        }

        return $slots;
    }

    /**
     * The earliest and the latest moment a session may start right now (UTC): the minimum notice from now, and how
     * far ahead one may book. The wizard's calendar disables the days outside it.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function window(?BookingSetting $settings = null): array
    {
        $settings ??= BookingSetting::current();
        $now = Carbon::now('UTC');

        return [$now->copy()->addHours($settings->min_notice_hours), $now->copy()->addDays($settings->max_advance_days)];
    }

    /**
     * Whether a session of this length may start at exactly this moment under every rule above, as a new request
     * or a time the studio proposes. The booking being moved can be left out of the bookings that hold time.
     */
    public function isAvailable(int $durationMinutes, CarbonInterface $startsAt, ?int $ignoreBookingId = null): bool
    {
        $start = Carbon::instance($startsAt)->utc();
        $day = $start->copy()->setTimezone(config('app.display_timezone'))->format('Y-m-d');

        return $this->slots($durationMinutes, $day, $day, $ignoreBookingId)
            ->contains(fn (Slot $slot) => $slot->startsAt->eq($start));
    }

    /**
     * The studio's schedule rules a session of this length starting at this moment breaks, in plain words: a closed
     * weekday, a blocked date, outside the opening hours, inside the minimum notice, beyond how far ahead one may book.
     * An empty list means it keeps every rule. Other bookings are NOT looked at here: a clash with a confirmed booking
     * is ScheduleConflictService's question, and the studio can never override it.
     *
     * @return list<string>
     */
    public function ruleViolations(int $durationMinutes, CarbonInterface $startsAt): array
    {
        $settings = BookingSetting::current();
        $timezone = config('app.display_timezone');
        $start = Carbon::instance($startsAt)->utc();
        $end = $start->copy()->addMinutes($durationMinutes);
        $day = LocalTime::format($start, 'Y-m-d');
        $reasons = [];

        $rule = AvailabilityRule::query()->where('weekday', LocalTime::in($start)->dayOfWeek)->first();

        if ($rule === null || ! $rule->is_open || ! $rule->opens_at || ! $rule->closes_at) {
            $reasons[] = 'The studio is closed on '.LocalTime::format($start, 'l').'s.';
        } else {
            $opens = Carbon::parse($day.' '.$rule->opens_at, $timezone);
            $closes = Carbon::parse($day.' '.$rule->closes_at, $timezone);

            if ($start->lt($opens) || $end->gt($closes)) {
                $reasons[] = 'It is outside the opening hours ('.LocalTime::format($opens, 'g:i A').' to '.LocalTime::format($closes, 'g:i A').').';
            }
        }

        // Read the same way as in slots(), so both agree on which days are blocked
        $blocked = BlockedDate::query()->whereBetween('date', [$day, $day])->first();

        if ($blocked !== null) {
            $reasons[] = 'The studio blocked '.LocalTime::format($start, 'D, M j, Y').($blocked->reason ? ' ('.$blocked->reason.')' : '').'.';
        }

        [$earliest, $latest] = $this->window($settings);

        if ($start->lt($earliest)) {
            $reasons[] = $start->lte(Carbon::now('UTC'))
                ? 'The time has already passed.'
                : "It is less than {$settings->min_notice_hours} hours from now (the minimum notice).";
        }

        if ($start->gt($latest)) {
            $reasons[] = "It is more than {$settings->max_advance_days} days ahead (how far ahead one may book).";
        }

        return $reasons;
    }
}
