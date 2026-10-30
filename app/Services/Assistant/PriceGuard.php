<?php

namespace App\Services\Assistant;

use App\Services\Assistant\Providers\ProviderMessage;
use App\Services\Assistant\Providers\ProviderRequest;
use Illuminate\Support\Facades\Log;

/**
 * Removes peso amounts the model made up. An amount in the reply is allowed only when the same amount is in the
 * request that was sent: the shop knowledge (system text) or what the customer or the thread already said (the
 * user-role messages). Every sentence or list line holding any other amount is removed; when nothing is left, a fixed
 * text asks the customer to message the studio.
 *
 * Only numbers with a peso marker are amounts: ₱1,500, PHP 1500, Php1500, P1,500, 1,500 pesos, 1500 piso, 1,500 php,
 * and ranges such as ₱500-₱1,000 or 500 to 1,000 pesos. A bare number (20 copies, a date, a time, an id) is not.
 */
class PriceGuard
{
    private const NUMBER = '\d{1,3}(?:,\d{3})+(?:\.\d{1,2})?|\d+(?:\.\d{1,2})?';

    private const RANGE = '\s*(?:-|–|—|to|hanggang)\s*';

    public function guard(string $reply, ProviderRequest $request): string
    {
        if ($this->amounts($reply) === []) {
            return $reply;
        }

        $allowed = $this->amounts($request->systemInstruction."\n".implode("\n", array_map(
            fn (ProviderMessage $message) => $message->text,
            array_filter($request->messages, fn (ProviderMessage $message) => $message->role === ProviderMessage::USER),
        )));

        $removed = 0;
        $lines = [];

        // Each line (a list item stays its own unit) is split into sentences; a blank line keeps the paragraph break,
        // a line that loses every sentence disappears
        foreach (preg_split('/\R/u', $reply) as $line) {
            if (trim($line) === '') {
                $lines[] = '';

                continue;
            }

            $kept = [];

            foreach (preg_split('/(?<=[.?!])\s+/u', trim($line)) as $sentence) {
                if (array_diff($this->amounts($sentence), $allowed) === []) {
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
            Log::info('assistant.prices_removed', ['removed' => $removed]);
        }

        $text = trim(preg_replace('/\n{3,}/u', "\n\n", implode("\n", $lines)) ?? '');

        return $text === '' ? config('assistant.messages.price_unverified') : $text;
    }

    /**
     * Every peso amount in the text, normalized: commas removed and a zero decimal part dropped, so ₱1,500, 1500.00 and
     * "1500 pesos" are all "1500".
     *
     * @return list<string>
     */
    public function amounts(string $text): array
    {
        $number = self::NUMBER;
        $range = self::RANGE;
        $found = [];

        // A marker before the number; a second number after a range sign may have its own marker or none
        preg_match_all("/(?:₱|(?<!\\p{L})[Pp][Hh][Pp]|(?<![\\p{L}\\d])P)\\s*({$number})(?:{$range}(?:₱|[Pp][Hh][Pp]|P)?\\s*({$number}))?/u", $text, $before, PREG_SET_ORDER);

        // A word after the number (pesos, piso, php); a range puts it after the second number only
        preg_match_all("/(?<![\\d.,])({$number})(?:{$range}({$number}))?\\s*(?:pesos?|piso|php)(?!\\p{L})/iu", $text, $after, PREG_SET_ORDER);

        foreach ([...$before, ...$after] as $match) {
            foreach (array_slice($match, 1) as $amount) {
                if ($amount !== '') {
                    $found[] = $this->normalize($amount);
                }
            }
        }

        return array_values(array_unique($found));
    }

    private function normalize(string $amount): string
    {
        $amount = str_replace(',', '', $amount);

        return str_contains($amount, '.') ? rtrim(rtrim($amount, '0'), '.') : $amount;
    }
}
