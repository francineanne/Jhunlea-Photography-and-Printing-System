<?php

namespace App\Services\Assistant\Exceptions;

use RuntimeException;

/** The provider answered 429. retryAfterSeconds is null when it did not say how long to wait. */
class ProviderRateLimited extends RuntimeException implements ProviderFailure
{
    public function __construct(public readonly ?int $retryAfterSeconds = null)
    {
        parent::__construct('The AI provider is rate limiting requests.');
    }

    public function reachedProvider(): bool
    {
        return true;
    }

    public function logContext(): array
    {
        return array_filter(['kind' => 'rate_limited', 'http_status' => 429, 'retry_after' => $this->retryAfterSeconds], fn ($value) => $value !== null);
    }
}
