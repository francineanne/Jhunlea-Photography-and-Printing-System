<?php

namespace App\Services\Assistant\Owner;

use Illuminate\Support\Carbon;

/**
 * Turns a tool's range parameters into shop calendar days (Phase 7E-3a): a preset, or explicit ISO dates (both
 * required, validated, the end included to the end of its day, at most MAX_SPAN_DAYS). Never free text: the planning
 * call picks a preset or writes the dates. An impossible or ambiguous range throws AmbiguousDateRange with a question
 * for the owner. Weeks start on Monday. "Today" is the Manila day of the current clock.
 */
class DateRangeResolver
{
    public const PRESETS = ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'last_7_days', 'last_30_days', 'all_time'];

    public const MAX_SPAN_DAYS = 366;

    /** @param array<string, mixed> $params range, from, to */
    public function resolve(array $params, string $default = 'today'): DateRange
    {
        $from = $params['from'] ?? null;
        $to = $params['to'] ?? null;
        $preset = $params['range'] ?? null;

        if ($from !== null || $to !== null) {
            if ($preset !== null) {
                throw new AmbiguousDateRange('Which period do you mean: '.str_replace('_', ' ', $preset).', or the dates you gave?');
            }

            return $this->explicit($from, $to);
        }

        return $this->preset($preset ?? $default);
    }

    public function preset(string $preset): DateRange
    {
        $today = $this->today();
        $day = fn (Carbon $date) => $date->toDateString();

        [$from, $to, $label] = match ($preset) {
            'today' => [$day($today), $day($today), 'today'],
            'yesterday' => [$day($today->copy()->subDay()), $day($today->copy()->subDay()), 'yesterday'],
            'this_week' => [$day($today->copy()->startOfWeek(Carbon::MONDAY)), $day($today), 'this week (from Monday)'],
            'last_week' => [$day($today->copy()->startOfWeek(Carbon::MONDAY)->subWeek()), $day($today->copy()->startOfWeek(Carbon::MONDAY)->subDay()), 'last week (Monday to Sunday)'],
            'this_month' => [$day($today->copy()->startOfMonth()), $day($today), 'this month'],
            'last_month' => [$day($today->copy()->startOfMonth()->subMonthNoOverflow()), $day($today->copy()->startOfMonth()->subDay()), 'last month'],
            'last_7_days' => [$day($today->copy()->subDays(6)), $day($today), 'the last 7 days'],
            'last_30_days' => [$day($today->copy()->subDays(29)), $day($today), 'the last 30 days'],
            'all_time' => [null, null, 'all time'],
            default => throw new AmbiguousDateRange('Which period do you mean? For example today, this week, last month, or two dates.'),
        };

        return new DateRange($from, $to, $label);
    }

    private function explicit(?string $from, ?string $to): DateRange
    {
        if ($from === null || $to === null) {
            throw new AmbiguousDateRange('Which dates exactly? Please give a start and an end date.');
        }

        $start = $this->date($from);
        $end = $this->date($to);

        if ($start === null || $end === null) {
            throw new AmbiguousDateRange('I could not read those dates. Which start and end date do you mean?');
        }

        if ($start->gt($end)) {
            throw new AmbiguousDateRange('The start date is after the end date. Which period do you mean?');
        }

        if ($start->diffInDays($end) + 1 > self::MAX_SPAN_DAYS) {
            throw new AmbiguousDateRange('That period is longer than a year. Please pick a shorter one, or ask for all time.');
        }

        return new DateRange($start->toDateString(), $end->toDateString(), $start->toDateString().' to '.$end->toDateString());
    }

    private function date(string $value): ?Carbon
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year) ? Carbon::createFromDate($year, $month, $day, config('app.display_timezone'))->startOfDay() : null;
    }

    private function today(): Carbon
    {
        return Carbon::now(config('app.display_timezone'))->startOfDay();
    }
}
