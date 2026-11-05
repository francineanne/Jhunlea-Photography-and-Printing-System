<?php

namespace App\Services\Assistant\Providers;

final class ProviderResponse
{
    /**
     * $inputTokens is what the provider billed as uncached input. For Claude (Phase 7E-2a) the cached part is counted
     * apart: $cacheReadTokens were read from the prompt cache, $cacheWriteTokens written to it. Null: not reported.
     */
    public function __construct(
        public readonly string $json,
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
        public readonly ?int $cacheReadTokens = null,
        public readonly ?int $cacheWriteTokens = null,
    ) {}
}
