<?php

namespace App\Services;

use App\Models\AvailabilityRule;
use App\Models\BlockedDate;
use App\Models\BookingSetting;
use App\Models\PhotographyBooking;
use App\Support\LocalTime;
use App\Support\Slot;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * The free session times for a package length, worked out from the studio's rules:
 * the weekly PHOTOSHOOT hours (availability_rules; the shop's own opening hours are a separate display text since
 * Phase 10A) and blocked dates, read in shop local time (config app.display_timezone); the minimum notice and how far
 * ahead one may book (booking_settings); slots every config booking.slot_step_minutes from the first hour; a session
 * must fit entirely inside the photoshoot hours; the bookings that already hold time, with the buffer
 * (ScheduleConflictService, PhotographyBooking::scopeHoldingTime); and the most photoshoots on one day, when set.
 * Never returns a time in the past.
 */
class AvailabilityService
{
    // Why a day offers no time, as the wizard words it (dayText())
    public const OPEN = 'open';

    public const CLOSED = 'closed';

    public const FULL = 'full';

    public const NO_FIT = 'no_fit';

    public const NONE = 'none';

    // Phase 10I: a day before the first day the minimum notice allows
    public const TOO_SOON = 'too_soon';

    public const NOT_SHOWN_TEXT = 'Times that are already requested or booked are not shown.';

    public function __construct(private readonly ScheduleConflictService $conflicts) {}

    /** The words for a day without a free time. */
    public static function dayText(string $state): string
    {
        return match ($state) {
            self::CLOSED => 'No photoshoots on this day.',
            self::FULL => 'Fully booked.',
            self::NO_FIT => 'No time long enough for this package on this day.',
            self::TOO_SOON => BookingSetting::current()->noticeReason(),
            default => 'No free time on this day.',
        };
    }

    /**
     * Free slots for a session of this length, from one local calendar day to another (both included).
     *
     * @param  string  $fromDate  Y-m-d in shop local time
     * @param  string  $toDate  Y-m-d in shop local time
     * @return Collection<int, Slot>
     *
     * @throws InvalidArgumentException when the range is backwards or longer than config booking.max_lookup_days
     *
     * $atCounter: the owner's walk-in booking form; still never a time that has passed, a closed or blocked day, or a
     * time that is not free. A day at its daily limit still lists its free times there (overview() marks it
     * "overLimit"; WalkInBookingService refuses it without the owner's explicit confirmation).
     */
    public function slots(int $durationMinutes, string $fromDate, string $toDate, ?int $ignoreBookingId = null, bool $atCounter = false): Collection
    {
        return $this->overview($durationMinutes, $fromDate, $toDate, $ignoreBookingId, $atCounter)->flatMap(fn (array $day) => $day['slots'])->values();
    }

    /**
     * Every day of the range with its free slots and what the wizard needs to explain the day: the state (open,
     * closed, full, no_fit, none), the photoshoot hours, and the stretches inside them that are not available (held
     * time with its buffer, and time that is too soon). Never who holds a time.
     *
     * @return Collection<string, array{state: string, opens: ?Carbon, closes: ?Carbon, busy: list<array{0: Carbon, 1: Carbon}>, slots: list<Slot>, overLimit: bool}>
     */
    public function overview(int $durationMinutes, string $fromDate, string $toDate, ?int $ignoreBookingId = null, bool $atCounter = false): Collection
    {
        $timezone = config('app.display_timezone');
        $from = Carbon::createFromFormat('!Y-m-d', $fromDate, $timezone);
        $to = Carbon::createFromFormat('!Y-m-d', $toDate, $timezone);

        if ($durationMinutes < 1 || $to->lt($from) || $from->diffInDays($to) >= config('booking.max_lookup_days')) {
            throw new InvalidArgumentException('That range of dates cannot be looked up.');
        }

        $settings = BookingSetting::current();
        [$earliest, $latest] = $this->window($settings);

        // At the counter (Phase 8A) there is no advance limit; since Phase 10I the minimum notice applies there too
        if ($atCounter) {
            $latest = $to->copy()->endOfDay()->utc();
        }

        $step = (int) config('booking.slot_step_minutes');
        $buffer = $settings->buffer_minutes;
        $limit = $settings->max_sessions_per_day;

        $rules = AvailabilityRule::query()->get()->keyBy('weekday');
        $blocked = BlockedDate::query()
            ->whereBetween('date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->pluck('date')
            ->map(fn ($date) => $date instanceof CarbonInterface ? $date->format('Y-m-d') : (string) $date)
            ->flip();

        // Every booking that holds time on these days, read once
        $taken = $this->conflicts->blockingBetween($from->copy()->startOfDay()->utc(), $to->copy()->endOfDay()->utc(), $ignoreBookingId);
        $perDay = $taken->countBy(fn (PhotographyBooking $booking) => LocalTime::format($booking->heldStart(), 'Y-m-d'));

        $days = collect();

        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $key = $day->format('Y-m-d');
            $rule = $rules->get($day->dayOfWeek);
            $info = ['state' => self::CLOSED, 'opens' => null, 'closes' => null, 'busy' => [], 'slots' => [], 'overLimit' => false];

            if ($rule === null || ! $rule->is_open || ! $rule->opens_at || ! $rule->closes_at || $blocked->has($key)) {
                $days->put($key, $info);

                continue;
            }

            $opens = Carbon::parse($key.' '.$rule->opens_at, $timezone);
            $closes = Carbon::parse($key.' '.$rule->closes_at, $timezone);
            $info['opens'] = $opens;
            $info['closes'] = $closes;
            $info['overLimit'] = $limit !== null && (int) $perDay->get($key, 0) >= $limit;
            $info['busy'] = $this->busy($taken, $opens, $closes, $buffer, $earliest);

            if ($info['overLimit'] && ! $atCounter) {
                $days->put($key, ['state' => self::FULL] + $info);

                continue;
            }

            $gap = false;

            for ($cursor = $opens->copy(); $cursor->lt($closes); $cursor->addMinutes($step)) {
                $start = $cursor->copy()->utc();

                if ($start->lt($earliest) || $start->gt($latest)) {
                    continue;
                }

                $end = $start->copy()->addMinutes($durationMinutes);

                if ($end->lte($closes) && ScheduleConflictService::mostAtOnce($taken, $start, $end, $buffer) < $settings->concurrent_sessions) {
                    $info['slots'][] = new Slot($start, $end);
                } elseif (! $gap) {
                    // Is at least the shortest step free here? Then the day has room, only not for this length
                    $gap = ScheduleConflictService::mostAtOnce($taken, $start, $start->copy()->addMinutes($step), $buffer) < $settings->concurrent_sessions;
                }
            }

            $info['state'] = match (true) {
                $info['slots'] !== [] => self::OPEN,
                $closes->lte($earliest) && $day->copy()->startOfDay()->utc()->lt($earliest) => self::TOO_SOON,
                $closes->lte($earliest) || $opens->gt($latest) => self::NONE,
                $gap => self::NO_FIT,
                default => self::FULL,
            };

            $days->put($key, $info);
        }

        return $days;
    }

    /**
     * Whether the day of this moment already has as many photoshoots as the owner's daily limit (bookings that hold
     * time count; the booking being moved can be left out). Always false when no limit is set.
     */
    public function dayIsFull(CarbonInterface $startsAt, ?int $ignoreBookingId = null): bool
    {
        $limit = BookingSetting::current()->max_sessions_per_day;

        if ($limit === null) {
            return false;
        }

        $day = LocalTime::format($startsAt, 'Y-m-d');

        return $this->conflicts->blockingBetween(LocalTime::dayStartUtc($day), LocalTime::dayEndUtc($day), $ignoreBookingId)
            ->filter(fn (PhotographyBooking $booking) => LocalTime::format($booking->heldStart(), 'Y-m-d') === $day)
            ->count() >= $limit;
    }

    public static function dailyLimitReason(): string
    {
        $limit = (int) BookingSetting::current()->max_sessions_per_day;

        return "The day already has {$limit} ".($limit === 1 ? 'photoshoot' : 'photoshoots').' (the daily limit).';
    }

    /**
     * The stretches of the photoshoot hours that are not available, merged and in order: time that is too soon (or
     * already passed) and held time widened by the buffer.
     *
     * @param  Collection<int, PhotographyBooking>  $taken
     * @return list<array{0: Carbon, 1: Carbon}>
     */
    private function busy(Collection $taken, Carbon $opens, Carbon $closes, int $buffer, Carbon $earliest): array
    {
        $ranges = [];

        if ($earliest->gt($opens)) {
            $ranges[] = [$opens->getTimestamp(), min($earliest->getTimestamp(), $closes->getTimestamp())];
        }

        foreach ($taken as $booking) {
            $start = max($booking->heldStart()->subMinutes($buffer)->getTimestamp(), $opens->getTimestamp());
            $end = min($booking->heldEnd()->addMinutes($buffer)->getTimestamp(), $closes->getTimestamp());

            if ($start < $end) {
                $ranges[] = [$start, $end];
            }
        }

        sort($ranges);
        $merged = [];

        foreach ($ranges as [$start, $end]) {
            $last = array_key_last($merged);

            if ($last !== null && $start <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $end);
            } else {
                $merged[] = [$start, $end];
            }
        }

        return array_map(fn (array $range) => [Carbon::createFromTimestampUTC($range[0]), Carbon::createFromTimestampUTC($range[1])], $merged);
    }

    /**
     * The earliest and the latest moment a session may start right now (UTC): the start of the first day the minimum
     * notice allows (Phase 10I, BookingSetting::earliestStart(), whole Manila days), and how far ahead one may book.
     * The wizard's calendar disables the days outside it.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function window(?BookingSetting $settings = null): array
    {
        $settings ??= BookingSetting::current();

        return [$settings->earliestStart(), Carbon::now('UTC')->addDays($settings->max_advance_days)];
    }

    /**
     * Phase 10I: why this start is too soon (a day before the first day the notice allows), or null. The same answer
     * for online requests, the studio's proposals and walk-ins; "Confirm anyway" never sets it aside at the counter.
     */
    public function noticeRefusal(CarbonInterface $startsAt, ?BookingSetting $settings = null): ?string
    {
        $settings ??= BookingSetting::current();

        return Carbon::instance($startsAt)->utc()->lt($settings->earliestStart()) ? $settings->noticeReason() : null;
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
     * weekday, a blocked date, outside the photoshoot hours, inside the minimum notice, beyond how far ahead one may book.
     * An empty list means it keeps every rule. Other bookings are NOT looked at here: a clash with a confirmed booking
     * is ScheduleConflictService's question, and the studio can never override it.
     *
     * At the counter (Phase 8A, a walk-in booking with the customer standing there) how far ahead one may book does not
     * apply; a time that has already passed is still reported. The minimum notice (Phase 10I) is NOT among these
     * overridable reasons: callers refuse it first with noticeRefusal(), for the counter too.
     *
     * @return list<string>
     */
    public function ruleViolations(int $durationMinutes, CarbonInterface $startsAt, bool $atCounter = false): array
    {
        $settings = BookingSetting::current();
        $timezone = config('app.display_timezone');
        $start = Carbon::instance($startsAt)->utc();
        $end = $start->copy()->addMinutes($durationMinutes);
        $day = LocalTime::format($start, 'Y-m-d');
        $reasons = [];

        $rule = AvailabilityRule::query()->where('weekday', LocalTime::in($start)->dayOfWeek)->first();

        if ($rule === null || ! $rule->is_open || ! $rule->opens_at || ! $rule->closes_at) {
            $reasons[] = 'There are no photoshoots on '.LocalTime::format($start, 'l').'s.';
        } else {
            $opens = Carbon::parse($day.' '.$rule->opens_at, $timezone);
            $closes = Carbon::parse($day.' '.$rule->closes_at, $timezone);

            if ($start->lt($opens) || $end->gt($closes)) {
                $reasons[] = 'It is outside the photoshoot hours ('.LocalTime::format($opens, 'g:i A').' to '.LocalTime::format($closes, 'g:i A').').';
            }
        }

        // Read the same way as in slots(), so both agree on which days are blocked
        $blocked = BlockedDate::query()->whereBetween('date', [$day, $day])->first();

        if ($blocked !== null) {
            $reasons[] = 'The studio blocked '.LocalTime::format($start, 'D, M j, Y').($blocked->reason ? ' ('.$blocked->reason.')' : '').'.';
        }

        [$earliest, $latest] = $this->window($settings);

        if ($atCounter) {
            if ($start->lte(Carbon::now('UTC'))) {
                $reasons[] = 'The time has already passed.';
            }

            return $reasons;
        }

        if ($start->lt($earliest)) {
            $reasons[] = $start->lte(Carbon::now('UTC'))
                ? 'The time has already passed.'
                : $settings->noticeReason();
        }

        if ($start->gt($latest)) {
            $reasons[] = "It is more than {$settings->max_advance_days} days ahead (how far ahead one may book).";
        }

        return $reasons;
    }
}
