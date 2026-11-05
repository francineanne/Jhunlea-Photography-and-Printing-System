<?php

namespace App\Services\Assistant\Eval;

/**
 * What one case attempt produced (Phase 7E-2b): the reply the customer would read, the outcome and intent, the cards,
 * whether it needs the owner and what about, and whether the provider was asked. $prompt is the text the provider
 * received, kept in memory only for the prompt_not_contains check and never written to a report.
 *
 * @phpstan-type Card array{type: string, id: int, name: string, price: ?string, summary: ?string, inclusions: list<string>, url: string}
 */
final class EvalObservation
{
    /**
     * @param  list<array<string, mixed>>  $cards
     * @param  array{type: string, id: int}|null  $about
     */
    public function __construct(
        public readonly string $reply,
        public readonly string $outcome,
        public readonly ?string $intent,
        public readonly array $cards,
        public readonly bool $needsOwner,
        public readonly ?array $about,
        public readonly bool $providerCalled,
        public readonly ?string $prompt = null,
        // Phase 7E-3a, owner cases: the tool names the run rows hold, and every fact the tool results gave
        public readonly array $tools = [],
        public readonly array $facts = [],
    ) {}
}
