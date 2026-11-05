<?php

namespace App\Services\Assistant;

use App\Enums\AssistantOutcome;
use App\Services\Assistant\Exceptions\ProviderFailure;
use App\Services\Assistant\Providers\AssistantProvider;
use App\Services\Assistant\Providers\ProviderRequest;
use App\Services\Assistant\Providers\ProviderResponse;

/**
 * What one walk along the provider chain produced (Phase 7E-3a, extracted from AssistantEngine): the provider that
 * ended up with the call, its response (null when every provider failed or none could be asked), the latency of the
 * last call that reached a provider (null when none did: then it counts toward nothing), the first provider of the
 * chain when the backup took the call, the calls that reached a provider and failed over (each keeps its own
 * assistant_runs row), the last failure and the request that was sent.
 */
final class ProviderCall
{
    /**
     * @param  list<array{provider: AssistantProvider, latency_ms: int, outcome: AssistantOutcome}>  $failedCalls
     */
    public function __construct(
        public readonly ?AssistantProvider $provider = null,
        public readonly ?ProviderResponse $response = null,
        public readonly ?int $latencyMs = null,
        public readonly ?string $fallbackFrom = null,
        public readonly array $failedCalls = [],
        public readonly ?ProviderFailure $failure = null,
        public readonly ?ProviderRequest $request = null,
    ) {}

    public function answered(): bool
    {
        return $this->response !== null && $this->request !== null;
    }

    public function viaBackup(): bool
    {
        return $this->fallbackFrom !== null;
    }
}
