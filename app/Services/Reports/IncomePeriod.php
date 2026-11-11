<?php

namespace App\Services\Reports;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The period of the income report (Phase 8C), in the shop's calendar days (Asia/Manila). Daily is one day, Weekly is
 * Monday to Sunday, Monthly is a calendar month, Custom is the owner's from / to, All time has no ends. A period can
 * never be browsed past the current one. The previous period has the same length: the day, week or calendar month
 * before, or as many days before a custom range; All time has none.
 */
final class IncomePeriod
{
    public const VIEWS = ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'custom' => 'Custom', 'all' => 'All time'];

    /** The views the report remembers per owner. */
    public const REMEMBERED = ['daily', 'weekly', 'monthly'];

    /** A custom range longer than this many days is drawn per month instead of per day. */
    public const DAILY_BARS_MAX = 62;

    /**
     * @param  'hour'|'day'|'month'  $bucket
     * @param  array{from: string, to: string, label: string}|null  $previous
     */
    private function __construct(
        public readonly string $view,
        public readonly ?string $from,
        public readonly ?string $to,
        public readonly string $date,
        public readonly string $label,
        public readonly string $bucket,
        public readonly ?array $previous,
        public readonly ?string $prevDate,
        public readonly ?string $nextDate,
        public readonly bool $isCurrent,
    ) {}

    public static function resolve(string $view, ?string $date, ?string $from, ?string $to, CarbonInterface $today): self
    {
        $today = Carbon::instance($today)->startOfDay();
        $day = $date !== null ? Carbon::parse($date, $today->getTimezone())->startOfDay() : $today->copy();
        $day = $day->greaterThan($today) ? $today->copy() : $day;
        $ymd = fn (CarbonInterface $value) => $value->format('Y-m-d');
        $long = fn (CarbonInterface $value) => $value->format('M j, Y');

        if ($view === 'weekly') {
            $start = $day->copy()->startOfWeek(CarbonInterface::MONDAY);
            $end = $start->copy()->addDays(6);
            $before = $start->copy()->subWeek();
            $after = $start->copy()->addWeek();
            $current = $start->equalTo($today->copy()->startOfWeek(CarbonInterface::MONDAY));

            return new self('weekly', $ymd($start), $ymd($end), $ymd($start), ($current ? 'This week · ' : '').$start->format('M j').' to '.$long($end), 'day',
                ['from' => $ymd($before), 'to' => $ymd($before->copy()->addDays(6)), 'label' => 'the week before'],
                $ymd($before), $after->lessThanOrEqualTo($today) ? $ymd($after) : null, $current);
        }

        if ($view === 'monthly') {
            $start = $day->copy()->startOfMonth();
            $before = $start->copy()->subMonthNoOverflow();
            $after = $start->copy()->addMonthNoOverflow();
            $current = $start->equalTo($today->copy()->startOfMonth());

            return new self('monthly', $ymd($start), $ymd($start->copy()->endOfMonth()), $ymd($start), ($current ? 'This month · ' : '').$start->format('F Y'), 'day',
                ['from' => $ymd($before), 'to' => $ymd($before->copy()->endOfMonth()), 'label' => $before->format('F Y')],
                $ymd($before), $after->lessThanOrEqualTo($today) ? $ymd($after) : null, $current);
        }

        if ($view === 'custom') {
            // Custom with no dates yet starts as this month up to today, ready to be changed
            if ($from === null && $to === null) {
                [$from, $to] = [$today->copy()->startOfMonth()->format('Y-m-d'), $ymd($today)];
            }

            $start = $from !== null ? Carbon::parse($from, $today->getTimezone())->startOfDay() : null;
            $end = $to !== null ? Carbon::parse($to, $today->getTimezone())->startOfDay() : null;
            $label = match (true) {
                $start && $end => $long($start).' to '.$long($end),
                (bool) $start => 'From '.$long($start),
                default => 'Up to '.$long($end),
            };
            $previous = null;
            $days = null;

            if ($start && $end) {
                $days = (int) $start->diffInDays($end) + 1;
                $previous = ['from' => $ymd($start->copy()->subDays($days)), 'to' => $ymd($start->copy()->subDay()), 'label' => 'the '.$days.' '.($days === 1 ? 'day' : 'days').' before'];
            }

            return new self('custom', $start ? $ymd($start) : null, $end ? $ymd($end) : null, $ymd($day), $label,
                $days !== null && $days <= self::DAILY_BARS_MAX ? 'day' : 'month', $previous, null, null, false);
        }

        if ($view === 'all') {
            return new self('all', null, null, $ymd($day), 'All time', 'month', null, null, null, true);
        }

        // Daily, the default
        $before = $day->copy()->subDay();
        $after = $day->copy()->addDay();
        $current = $day->equalTo($today);
        $label = match (true) {
            $current => 'Today · '.$day->format('l, M j, Y'),
            $day->equalTo($today->copy()->subDay()) => 'Yesterday · '.$day->format('l, M j, Y'),
            default => $day->format('l, M j, Y'),
        };

        return new self('daily', $ymd($day), $ymd($day), $ymd($day), $label, 'hour',
            ['from' => $ymd($before), 'to' => $ymd($before), 'label' => 'the day before'],
            $ymd($before), $after->lessThanOrEqualTo($today) ? $ymd($after) : null, $current);
    }

    /** The ledger's filter array for this period. */
    public function filters(): array
    {
        return ['method' => null, 'status' => null, 'search' => '', 'from' => $this->from, 'to' => $this->to];
    }

    /** The Payments ledger showing the same dates. */
    public function ledgerQuery(): array
    {
        return $this->from === null && $this->to === null ? ['range' => 'all'] : array_filter(['from' => $this->from, 'to' => $this->to]);
    }
}
