<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The one place that turns a stored date into the time people read. Dates are stored in UTC; they are shown in the
 * shop's own time zone (config app.display_timezone). Use it through <x-ui.date> in views.
 */
class LocalTime
{
    public const DATE_TIME = 'M j, Y g:i A';

    public const DATE = 'M j, Y';

    public static function in(CarbonInterface|string $value): Carbon
    {
        return self::utc($value)->setTimezone(config('app.display_timezone'));
    }

    /** The moment itself, in UTC, for the datetime attribute of <time>. */
    public static function iso(CarbonInterface|string $value): string
    {
        return self::utc($value)->toIso8601ZuluString();
    }

    private static function utc(CarbonInterface|string $value): Carbon
    {
        // A string without a zone is a stored value, and stored values are UTC
        return ($value instanceof CarbonInterface ? Carbon::instance($value) : Carbon::parse($value, 'UTC'))->utc();
    }

    public static function format(CarbonInterface|string|null $value, string $format = self::DATE_TIME): string
    {
        return $value === null || $value === '' ? '—' : self::in($value)->format($format);
    }

    /** The calendar day in the shop's time zone, turned into the UTC range that holds it (for "from / to" filters). */
    public static function dayStartUtc(string $date): Carbon
    {
        return Carbon::parse($date, config('app.display_timezone'))->startOfDay()->utc();
    }

    public static function dayEndUtc(string $date): Carbon
    {
        return Carbon::parse($date, config('app.display_timezone'))->endOfDay()->utc();
    }
}
