<?php

namespace App\Services\Assistant\Exceptions;

use RuntimeException;

/** The provider answered, but not with the expected shape, or the reply breaks the output contract. */
class ProviderBadResponse extends RuntimeException
{
    /** @return array<string, string> */
    public function logContext(): array
    {
        return ['kind' => 'bad_response'];
    }
}
