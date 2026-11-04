<?php

namespace App\Services\Assistant\Exceptions;

use RuntimeException;

/**
 * The provider answered, but not with the expected shape, or the reply breaks the output contract. A broken body from
 * the provider is worth asking the backup about; a model that declined the request on purpose (a refusal) is not:
 * $failsOver says which.
 */
class ProviderBadResponse extends RuntimeException implements ProviderFailure
{
    public function __construct(string $message = '', public readonly string $kind = 'bad_response', public readonly bool $failsOver = true)
    {
        parent::__construct($message);
    }

    /** The model declined to answer (Claude's stop_reason "refusal"): the same question goes to no other provider. */
    public static function refusal(): self
    {
        return new self('The AI provider declined the request.', 'refusal', false);
    }

    public function reachedProvider(): bool
    {
        return true;
    }

    public function logContext(): array
    {
        return ['kind' => $this->kind];
    }
}
