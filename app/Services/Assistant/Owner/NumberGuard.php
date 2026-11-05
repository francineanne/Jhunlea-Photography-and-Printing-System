<?php

namespace App\Services\Assistant\Owner;

use App\Services\Assistant\GuardStats;
use App\Services\Assistant\PriceGuard;
use Illuminate\Support\Facades\Log;

/**
 * Every number in an owner answer must come from the tool facts (Phase 7E-3a). The reply and the facts are read the
 * same way into values: amounts ("₱1,200.00", "P1200", "1,200 pesos", "1.2k"), counts and percentages ("12.5%") as
 * numbers, dates ("Nov 5", "November 5, 2026", "5 Nov", "2026-11-05") as month and day, times ("2:00 PM") as hour and
 * minute, and the relative words (today, yesterday, this week, last month, kahapon ...) as themselves ("ngayon" and
 * "bukas" also mean "now" and "open", so they are not read). A
 * sentence holding a value that is not in the facts, the owner's question or the small allowed set (1, 2 and 3, a
 * year) is removed; when nothing is left the fixed text is used. Reference numbers (PR-000123), web and email
 * addresses are never read as numbers.
 */
class NumberGuard
{
    private const MONTHS = ['jan' => 1, 'january' => 1, 'enero' => 1, 'feb' => 2, 'february' => 2, 'pebrero' => 2, 'mar' => 3, 'march' => 3, 'marso' => 3,
        'apr' => 4, 'april' => 4, 'abril' => 4, 'may' => 5, 'mayo' => 5, 'jun' => 6, 'june' => 6, 'hunyo' => 6, 'jul' => 7, 'july' => 7, 'hulyo' => 7,
        'aug' => 8, 'august' => 8, 'agosto' => 8, 'sep' => 9, 'sept' => 9, 'september' => 9, 'setyembre' => 9, 'oct' => 10, 'october' => 10, 'oktubre' => 10,
        'nov' => 11, 'november' => 11, 'nobyembre' => 11, 'dec' => 12, 'december' => 12, 'disyembre' => 12];

    public const RELATIVE = ['today', 'yesterday', 'tomorrow', 'this week', 'last week', 'next week', 'this month', 'last month', 'next month',
        'kahapon', 'ngayong linggo', 'noong isang linggo', 'ngayong buwan', 'noong isang buwan', 'nakaraang buwan', 'nakaraang linggo'];

    private const ALWAYS = ['n:1', 'n:2', 'n:3'];

    public function __construct(private readonly PriceGuard $prices) {}

    /** @param list<string> $facts */
    public function guard(string $reply, array $facts, string $question, string $fallback): string
    {
        $allowed = array_flip([...self::ALWAYS, ...$this->values(implode("\n", $facts)), ...$this->values($question)]);
        $removed = 0;
        $lines = [];

        foreach (preg_split('/\R/u', $reply) as $line) {
            if (trim($line) === '') {
                $lines[] = '';

                continue;
            }

            $kept = [];

            foreach (preg_split('/(?<=[.?!])\s+/u', trim($line)) as $sentence) {
                $unknown = array_filter($this->values($sentence), fn (string $value) => ! isset($allowed[$value]) && ! preg_match('/^n:(?:19|20)\d\d$/', $value));

                if ($unknown === []) {
                    $kept[] = $sentence;
                } else {
                    $removed++;
                }
            }

            if ($kept !== []) {
                $lines[] = implode(' ', $kept);
            }
        }

        if ($removed > 0) {
            Log::info('assistant.numbers_removed', ['removed' => $removed]);
        }

        $text = trim(preg_replace('/\n{3,}/u', "\n\n", implode("\n", $lines)) ?? '');

        app(GuardStats::class)->removed(GuardStats::NUMBER, $removed, $text === '');

        return $text === '' ? $fallback : $text;
    }

    /**
     * The values of a text: "n:1200", "d:11-05", "t:14:00", "r:yesterday".
     *
     * @return list<string>
     */
    public function values(string $text): array
    {
        $values = [];
        // Never numbers: references, web and email addresses
        $text = (string) preg_replace('~\b(?:PR|PB|PAY)[\s-]?\d+\b|\S+@\S+|\bhttps?://\S+|\bwww\.\S+~iu', ' ', $text);

        foreach (self::RELATIVE as $word) {
            if (preg_match('/(?<!\p{L})'.preg_quote($word, '/').'(?!\p{L})/iu', $text)) {
                $values[] = 'r:'.$word;
            }
        }

        // "may" is also the Filipino "there is" ("May 3 orders"): it is a month only with a year ("May 5, 2026")
        $months = implode('|', array_diff(array_keys(self::MONTHS), ['may']));
        $text = (string) preg_replace_callback('/\bMay\s+(\d{1,2}),?\s+(\d{4})\b/u', function (array $m) use (&$values) {
            $values[] = sprintf('d:05-%02d', (int) $m[1]);
            $values[] = 'n:'.(int) $m[2];

            return ' ';
        }, $text);

        // ISO dates
        $text = (string) preg_replace_callback('/\b(\d{4})-(\d{2})-(\d{2})\b/', function (array $m) use (&$values) {
            $values[] = 'd:'.$m[2].'-'.$m[3];
            $values[] = 'n:'.(int) $m[1];

            return ' ';
        }, $text);

        // "Nov 5", "November 5, 2026", "Nov. 5th"
        $text = (string) preg_replace_callback('/\b('.$months.')\.?\s+(\d{1,2})(?:st|nd|rd|th)?\b(?:,?\s+(\d{4})\b)?/iu', function (array $m) use (&$values) {
            $values[] = sprintf('d:%02d-%02d', self::MONTHS[mb_strtolower($m[1])], (int) $m[2]);

            if (($m[3] ?? '') !== '') {
                $values[] = 'n:'.(int) $m[3];
            }

            return ' ';
        }, $text);

        // "5 Nov", "5 November 2026"
        $text = (string) preg_replace_callback('/\b(\d{1,2})(?:st|nd|rd|th)?\s+(?:ng\s+)?('.$months.')\b(?:,?\s+(\d{4})\b)?/iu', function (array $m) use (&$values) {
            $values[] = sprintf('d:%02d-%02d', self::MONTHS[mb_strtolower($m[2])], (int) $m[1]);

            if (($m[3] ?? '') !== '') {
                $values[] = 'n:'.(int) $m[3];
            }

            return ' ';
        }, $text);

        // Times: "2:00 PM", "2 pm", "14:30"
        $text = (string) preg_replace_callback('/\b(\d{1,2})(?::(\d{2}))?\s*([ap])\.?\s?m\.?(?!\p{L})/iu', function (array $m) use (&$values) {
            $hour = (int) $m[1] % 12 + (strtolower($m[3]) === 'p' ? 12 : 0);
            $values[] = sprintf('t:%02d:%02d', $hour, (int) ($m[2] ?? 0));

            return ' ';
        }, $text);
        $text = (string) preg_replace_callback('/\b([01]?\d|2[0-3]):([0-5]\d)\b/', function (array $m) use (&$values) {
            $values[] = sprintf('t:%02d:%02d', (int) $m[1], (int) $m[2]);

            return ' ';
        }, $text);

        // Amounts the price guard reads (₱1,200.00, P1200, 1,200 pesos), then "1.2k"
        foreach ($this->prices->amounts($text) as $amount) {
            $values[] = 'n:'.$amount;
        }

        $text = (string) preg_replace_callback('/(\d+(?:\.\d+)?)\s*[kK](?!\p{L})/u', function (array $m) use (&$values) {
            $values[] = 'n:'.self::number((string) ((float) $m[1] * 1000));

            return ' ';
        }, $text);

        // Every other number: counts, percentages, plain amounts
        preg_match_all('/(?<![\d.,])\d{1,3}(?:,\d{3})+(?:\.\d+)?|(?<![\d.,])\d+(?:\.\d+)?/u', $text, $numbers);

        foreach ($numbers[0] as $number) {
            $values[] = 'n:'.self::number($number);
        }

        return array_values(array_unique($values));
    }

    private static function number(string $number): string
    {
        $number = str_replace(',', '', $number);

        return str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : (ltrim($number, '0') ?: '0');
    }
}
