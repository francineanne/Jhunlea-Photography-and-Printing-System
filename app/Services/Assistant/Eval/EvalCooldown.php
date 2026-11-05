<?php

namespace App\Services\Assistant\Eval;

use App\Services\Assistant\ProviderCooldown;
use Illuminate\Support\Facades\Cache;

/**
 * The real provider cooldown, bound in the eval process only (Phase 7E-2c), that also remembers the last 429 (which
 * provider, and its retry-after) so EvalRunner can wait and retry that case once, and lets the runner clear a
 * provider's cooldown in the sandbox so one 429 does not fail every case after it. The website never uses it.
 */
class EvalCooldown extends ProviderCooldown
{
    /** @var array{provider: string, seconds: ?int}|null */
    public ?array $lastRateLimit = null;

    public function start(string $provider, ?int $retryAfterSeconds): void
    {
        $this->lastRateLimit = ['provider' => $provider, 'seconds' => $retryAfterSeconds];
        parent::start($provider, $retryAfterSeconds);
    }

    public function clear(string $provider): void
    {
        Cache::forget(self::CACHE_KEY.$provider);
    }
}
