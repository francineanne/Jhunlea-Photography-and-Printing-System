<?php

namespace App\Services\Assistant\Providers;

use App\Services\Assistant\Exceptions\ProviderBadResponse;
use App\Services\Assistant\Exceptions\ProviderRateLimited;
use App\Services\Assistant\Exceptions\ProviderUnavailable;

interface AssistantProvider
{
    /**
     * Sends one request and returns the model's raw JSON text (the output contract is checked by ResponseValidator).
     *
     * @throws ProviderUnavailable no key, network error, timeout or a server error
     * @throws ProviderRateLimited the provider answered 429
     * @throws ProviderBadResponse the body is not the expected shape
     */
    public function generate(ProviderRequest $request): ProviderResponse;

    /** Short name stored in assistant_runs.provider. */
    public function name(): string;

    public function model(): ?string;
}
