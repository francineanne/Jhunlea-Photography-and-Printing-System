<?php

namespace App\Support;

use Illuminate\Support\Number;

/** The one place that turns a stored decimal into a peso string. */
class Money
{
    public static function format(string|int|float|null $amount): string
    {
        if ($amount === null || $amount === '') {
            return '—';
        }

        return Number::currency((float) $amount, 'PHP', 'en_PH');
    }

    /** For option price modifiers: "+₱25.00", or "No extra charge" when zero. */
    public static function modifier(string|int|float|null $amount): string
    {
        if ($amount === null || (float) $amount === 0.0) {
            return 'No extra charge';
        }

        return '+'.self::format($amount);
    }
}
