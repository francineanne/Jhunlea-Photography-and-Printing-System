<?php

namespace App\Services\Assistant\Exceptions;

use RuntimeException;

/** The provider answered, but not with the expected shape, or the reply breaks the output contract. */
class ProviderBadResponse extends RuntimeException implements ProviderFailure
{
    public function reachedProvider(): bool
    {
        return true;
    }

    public function logContext(): array
    {
        return ['kind' => 'bad_response'];
    }
}
