<?php

namespace App\Services\Assistant;

use Illuminate\Support\Facades\Cache;

/**
 * After the provider answers 429, every assistant call goes straight to the fallback until the wait is over, even when
 * the shop's own budget has room. The wait is the provider's retry-after (a minute when it gave none), never longer
 * than config assistant.limits.provider_cooldown_max_seconds.
 */
class ProviderCooldown
{
    public const CACHE_KEY = 'assistant:provider-cooldown';

    private const DEFAULT_SECONDS = 60;

    public function start(?int $retryAfterSeconds): void
    {
        $max = max(1, (int) config('assistant.limits.provider_cooldown_max_seconds'));
        $seconds = min(max(1, $retryAfterSeconds ?? self::DEFAULT_SECONDS), $max);

        Cache::put(self::CACHE_KEY, true, $seconds);
    }

    public function active(): bool
    {
        return Cache::has(self::CACHE_KEY);
    }
}
