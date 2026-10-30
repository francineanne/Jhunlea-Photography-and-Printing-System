<?php

namespace App\Services\Assistant\Providers;

/** One turn sent to the model. Customer text is always in the "user" role, never in the system instruction. */
final class ProviderMessage
{
    public const USER = 'user';

    public const MODEL = 'model';

    public function __construct(
        public readonly string $role,
        public readonly string $text,
    ) {}
}
