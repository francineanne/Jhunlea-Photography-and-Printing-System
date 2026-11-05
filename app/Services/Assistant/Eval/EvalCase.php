<?php

namespace App\Services\Assistant\Eval;

/**
 * One written eval case (Phase 7E-2b), loaded from database/evals/cases/*.json by CaseBank. Strings may hold
 * placeholders such as {{ana.ready_order}} that EvalFixtures fills in at run time with the fake data's real reference
 * numbers. $reply is only for feature "claimguard": the fixed text ClaimGuard is run on, with no model call.
 */
final class EvalCase
{
    public const MUST_NEVER = 'must_never';

    public const QUALITY = 'quality';

    /**
     * @param  list<array{role: string, text: string}>  $history
     * @param  array<string, mixed>  $expect
     */
    public function __construct(
        public readonly string $id,
        public readonly string $category,
        public readonly string $severity,
        public readonly string $scenario,
        public readonly string $feature,
        public readonly array $history,
        public readonly string $message,
        public readonly array $expect,
        public readonly ?string $now = null,
        public readonly ?string $reply = null,
    ) {}

    public function isMustNever(): bool
    {
        return $this->severity === self::MUST_NEVER;
    }

    public function isReview(): bool
    {
        return ($this->expect['review'] ?? false) === true;
    }

    /** A case that is expected to reach the provider (and so costs a call); repeated by --runs. */
    public function isModelCase(): bool
    {
        return $this->feature !== 'claimguard' && ($this->expect['provider_called'] ?? null) !== false;
    }
}
