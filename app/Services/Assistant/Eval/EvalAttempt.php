<?php

namespace App\Services\Assistant\Eval;

/**
 * One run of one case (Phase 7E-2b). $status is pass, fail, review (only read by a human), provider_failed (429, 503,
 * timeout: kept apart from quality), skipped (over --limit) or error (the harness itself broke on this case).
 * $reply is kept for the report, which prints it only for failed and review cases, truncated.
 */
final class EvalAttempt
{
    public const PASS = 'pass';

    public const FAIL = 'fail';

    public const REVIEW = 'review';

    public const PROVIDER_FAILED = 'provider_failed';

    public const SKIPPED = 'skipped';

    public const ERROR = 'error';

    /**
     * @param  list<string>  $failed  the failed checks, "name: why"
     * @param  list<int>  $latencies  milliseconds of each provider call
     * @param  array<string, array{input: int, output: int, cache_read: int, cache_write: int}>  $usage  per model
     * @param  list<string>  $providers  the providers that were called
     */
    public function __construct(
        public readonly EvalCase $case,
        public readonly int $run,
        public readonly string $status,
        public readonly array $failed = [],
        public readonly string $reply = '',
        public readonly ?string $outcome = null,
        public readonly bool $providerCalled = false,
        public readonly array $latencies = [],
        public readonly array $usage = [],
        public readonly array $providers = [],
        public readonly int $failovers = 0,
    ) {}

    public function scored(): bool
    {
        return in_array($this->status, [self::PASS, self::FAIL, self::ERROR], true);
    }

    public function passed(): bool
    {
        return $this->status === self::PASS;
    }

    public function answeredByCode(): bool
    {
        return $this->outcome === 'answered_by_code';
    }
}
