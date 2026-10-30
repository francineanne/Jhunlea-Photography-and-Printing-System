<?php

namespace App\Services\Assistant\Providers;

final class ProviderResponse
{
    public function __construct(
        public readonly string $json,
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
    ) {}
}
