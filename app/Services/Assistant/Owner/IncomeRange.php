<?php

namespace App\Services\Assistant\Owner;

use App\Services\Reports\IncomePeriod;
use App\Support\LocalTime;
use Illuminate\Support\Carbon;

/**
 * Turns the owner's Ask Lumi date range (DateRangeResolver) into the income report's own period (Phase 8D), so Lumi
 * and the Reports page read the SAME dates and the same previous period: one day is Daily, Monday to Sunday (or to
 * today in this week) is Weekly, the 1st to the month's end (or to today in this month) is Monthly, no dates is All
 * time, anything else is Custom. A range that starts after today is in the future: it has no records yet, and the
 * report is never asked (IncomePeriod would show today instead).
 */
final class IncomeRange
{
    public static function period(DateRange $range): ?IncomePeriod
    {
        $today = LocalTime::in(Carbon::now('UTC'))->startOfDay();

        if ($range->from === null && $range->to === null) {
            return IncomePeriod::resolve('all', null, null, null, $today);
        }

        $from = $range->from !== null ? Carbon::parse($range->from, $today->getTimezone())->startOfDay() : null;
        $to = Carbon::parse($range->to ?? $today->format('Y-m-d'), $today->getTimezone())->startOfDay();

        if ($from?->greaterThan($today)) {
            return null;
        }

        $view = match (true) {
            $from === null => 'custom',
            $from->equalTo($to) => 'daily',
            $from->isMonday() && ($to->equalTo($from->copy()->addDays(6)) || ($to->equalTo($today) && $to->lessThan($from->copy()->addDays(6)))) => 'weekly',
            $from->day === 1 && ($to->equalTo($from->copy()->endOfMonth()->startOfDay()) || ($to->equalTo($today) && $to->isSameMonth($from))) => 'monthly',
            default => 'custom',
        };

        return $view === 'custom'
            ? IncomePeriod::resolve('custom', null, $from?->format('Y-m-d'), $to->format('Y-m-d'), $today)
            : IncomePeriod::resolve($view, $from->format('Y-m-d'), null, null, $today);
    }

    /** The income report's address for the same period. @return array<string, string> */
    public static function reportQuery(IncomePeriod $period): array
    {
        return match ($period->view) {
            'daily', 'weekly', 'monthly' => ['view' => $period->view, 'date' => $period->date],
            'custom' => array_filter(['view' => 'custom', 'from' => $period->from, 'to' => $period->to]),
            default => ['view' => 'all'],
        };
    }
}
