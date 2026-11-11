<?php

namespace App\Services\Assistant;

/**
 * How many sentences the reply guards removed in this request, and whether a fixed text replaced a whole reply (Phase
 * 7E-2e). Counts only: never a sentence. Bound per request (scoped), so the eval reads and resets it per case to see a
 * guard that suddenly removes more (a new false positive) without storing any text.
 */
final class GuardStats
{
    public const CLAIM = 'claim_guard';

    public const PRICE = 'price_guard';

    public const NUMBER = 'number_guard';

    /** The owner's answers: sentences with "revenue", "sales" or "profit" (8D). */
    public const MONEY_WORDS = 'money_word_guard';

    public const DANGLING_COLON = 'dangling_colon';

    /** @var array<string, int> */
    private array $removed = [];

    /** @var array<string, int> */
    private array $replaced = [];

    public function removed(string $guard, int $sentences, bool $replacedWholeReply = false): void
    {
        if ($sentences > 0) {
            $this->removed[$guard] = ($this->removed[$guard] ?? 0) + $sentences;
        }

        if ($replacedWholeReply) {
            $this->replaced[$guard] = ($this->replaced[$guard] ?? 0) + 1;
        }
    }

    /** @return array{removed: array<string, int>, replaced: array<string, int>} */
    public function snapshot(): array
    {
        return ['removed' => $this->removed, 'replaced' => $this->replaced];
    }

    public function reset(): void
    {
        $this->removed = [];
        $this->replaced = [];
    }
}
