<?php

namespace App\Services\Assistant;

/**
 * Takes personal details out of a message before it leaves for the AI provider: email addresses, phone numbers
 * (Philippine mobile and landline formats, with spaces, dashes, dots or brackets) and long digit runs (account,
 * card or ID numbers). The model only ever sees the placeholders.
 */
class Redactor
{
    public const EMAIL = '[email]';

    public const PHONE = '[phone]';

    public const NUMBER = '[number]';

    public function redact(string $text): string
    {
        $text = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9-]+(?:\.[A-Z0-9-]+)*\.[A-Z]{2,}/i', self::EMAIL, $text) ?? '';

        // Philippine mobile: 09XX XXX XXXX, +63 9XX XXX XXXX, 639XXXXXXXXX
        $text = preg_replace('/(?<!\d)(?:\+?\s?63|0)[\s.-]?9\d{2}[\s.-]?\d{3}[\s.-]?\d{4}(?!\d)/', self::PHONE, $text) ?? '';

        $text = preg_replace('/\d{8,}/', self::NUMBER, $text) ?? '';

        // Anything else that looks like a phone number: 7 or more digits split by spaces, dashes, dots or brackets,
        // for example (02) 8123-4567. Dates written as 2026-11-10 are kept
        return preg_replace_callback('/\+?\(?\d(?:[\s().-]{0,2}\d){6,15}/', function (array $match): string {
            if (preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $match[0])) {
                return $match[0];
            }

            return strlen((string) preg_replace('/\D/', '', $match[0])) >= 7 ? self::PHONE : $match[0];
        }, $text) ?? '';
    }
}
