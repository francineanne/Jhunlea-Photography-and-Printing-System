<?php

namespace App\Services\Assistant\Owner;

use App\Services\Assistant\GuardStats;
use Illuminate\Support\Facades\Log;

/**
 * The owner's answers never say "revenue", "sales" or "profit" (8D fix after the live eval): the shop records payments
 * only, which is its income, and does not track costs. The model repeated the owner's own words even in a denial ("The
 * system does not track revenue, sales or profit"), so a prompt rule was not enough. Every sentence holding one of the
 * words (whole words, any case, plurals, in English or inside a Filipino sentence) is removed, like NumberGuard does;
 * when the owner's question used one of them, one fixed sentence in the owner's language says what the system records
 * instead. When nothing is left, the caller's safe fallback is used.
 */
final class MoneyWordGuard
{
    public const WORDS = '/(?<!\p{L})(?:revenues?|sales|profits?|profitable)(?!\p{L})/iu';

    public function asked(string $question): bool
    {
        return preg_match(self::WORDS, $question) === 1;
    }

    public function guard(string $reply, string $question, string $notice, string $fallback): string
    {
        $removed = 0;
        $lines = [];

        foreach (preg_split('/\R/u', $reply) as $line) {
            if (trim($line) === '') {
                $lines[] = '';

                continue;
            }

            $kept = [];
            foreach (preg_split('/(?<=[.?!])\s+/u', trim($line)) as $sentence) {
                if (preg_match(self::WORDS, $sentence) === 1) {
                    $removed++;
                } else {
                    $kept[] = $sentence;
                }
            }

            if ($kept !== []) {
                $lines[] = implode(' ', $kept);
            }
        }

        $text = trim(preg_replace('/\n{3,}/u', "\n\n", implode("\n", $lines)) ?? '');

        if ($removed > 0) {
            Log::info('assistant.money_words_removed', ['removed' => $removed]);
        }

        if ($this->asked($question)) {
            $text = trim($notice.($text === '' ? '' : ' '.$text));
        }

        app(GuardStats::class)->removed(GuardStats::MONEY_WORDS, $removed, $text === '');

        return $text === '' ? $fallback : $text;
    }
}
