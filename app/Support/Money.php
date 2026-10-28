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

    /** A decimal string such as "12.5" as whole centavos (1250). Arithmetic is done on these integers, never on floats. */
    public static function toCents(string|int|float $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    /** Whole centavos as the two-decimal string the database stores: 1250 becomes "12.50". */
    public static function fromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign.intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
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
