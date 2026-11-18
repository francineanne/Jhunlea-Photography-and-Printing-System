<?php

namespace App\Support;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Phase 10C: THE one rule for a customer's phone (registration, the account page, a new walk-in customer, a booking's
 * contact number). A Philippine mobile number, typed the way people write it: "0917 123 4567", "09171234567",
 * "+63 917 123 4567", "63-917-123-4567", "(0917) 123-4567". Spaces, hyphens, dots and brackets are ignored; it is
 * stored as 09XXXXXXXXX. Landlines and foreign numbers are refused (the shop's own business phone is a separate setting
 * with its own rule).
 */
final class PhilippineMobile implements ValidationRule
{
    public const MESSAGE = 'Enter a Philippine mobile number, for example 0917 123 4567 or +63 917 123 4567.';

    /** The longest value the field accepts as typed (with spaces, the +63 and brackets). */
    public const MAX_TYPED = 20;

    /** "09171234567", or null when it is not a Philippine mobile number. */
    public static function normalize(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        if ($value === null || mb_strlen($value) > self::MAX_TYPED) {
            return null;
        }

        $digits = (string) preg_replace('/[\s\-.()]/u', '', $value);

        $digits = match (true) {
            preg_match('/^\+639\d{9}$/', $digits) === 1 => '0'.substr($digits, 3),
            preg_match('/^639\d{9}$/', $digits) === 1 => '0'.substr($digits, 2),
            default => $digits,
        };

        return preg_match('/^09\d{9}$/', $digits) === 1 ? $digits : null;
    }

    /**
     * Phase 10G: how a number READS on a page: "0991 471 0185" for a Philippine mobile number, else the text as stored
     * (a landline or anything else the owner typed). Display only; the stored value never changes.
     */
    public static function display(?string $value): string
    {
        $mobile = self::normalize($value);

        return $mobile === null ? trim((string) $value) : substr($mobile, 0, 4).' '.substr($mobile, 4, 3).' '.substr($mobile, 7);
    }

    /** Phase 10G: the tel: address: +63 and the 10 digits for a mobile number, else the digits and + as typed. */
    public static function telHref(?string $value): string
    {
        $mobile = self::normalize($value);

        return 'tel:'.($mobile === null ? preg_replace('/[^0-9+]/', '', (string) $value) : '+63'.substr($mobile, 1));
    }

    public static function isValid(?string $value): bool
    {
        return self::normalize($value) !== null;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isValid($value)) {
            $fail(self::MESSAGE);
        }
    }
}
