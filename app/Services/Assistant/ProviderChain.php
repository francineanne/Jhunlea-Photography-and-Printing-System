<?php

namespace App\Services\Assistant;

use App\Enums\AssistantFeature;
use App\Enums\AssistantOutcome;
use App\Services\Assistant\Exceptions\ProviderBadResponse;
use App\Services\Assistant\Exceptions\ProviderFailure;
use App\Services\Assistant\Exceptions\ProviderRateLimited;
use App\Services\Assistant\Exceptions\ProviderUnavailable;
use App\Services\Assistant\Providers\ProviderRequest;
use App\Services\Assistant\Providers\ProviderResolver;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * THE provider failover and counting rule (Phase 7E-1, extracted from AssistantEngine in 7E-3a so the owner's Ask
 * Lumi uses the same code). The providers of the chain are asked in order, each skipped while its own 429 cooldown
 * runs or when it has no key. The next provider is asked ONLY when one is rate limiting, unavailable or sent a broken
 * body; a real answer never goes to a second provider. A call counts toward every limit only once the provider sent an
 * HTTP response (countCall).
 */
class ProviderChain
{
    public function __construct(
        private readonly ProviderResolver $resolver,
        private readonly LimitService $limits,
        private readonly ProviderCooldown $cooldown,
    ) {}

    /** @param Closure(): ProviderRequest $buildRequest built once, and only when a provider can be asked */
    public function call(AssistantFeature $feature, AssistantActor $actor, Closure $buildRequest): ProviderCall
    {
        $request = null;
        $response = null;
        $current = null;
        $latencyMs = null;
        $fallbackFrom = null;
        $failure = null;
        $failedCalls = [];
        $chain = $this->resolver->chain();

        foreach ($chain as $position => $name) {
            $provider = $this->cooldown->active($name) ? null : $this->resolver->make($name);

            if ($provider === null) {
                continue;
            }

            // A provider before this one was skipped or failed: the call is the backup's
            $fallbackFrom = $position > 0 ? $chain[0] : null;
            $current = $provider;
            $latencyMs = null;
            $request ??= $buildRequest();
            $started = hrtime(true);

            try {
                $response = $provider->generate($request);
                $latencyMs = $this->countCall($feature, $actor, $started);
                $failure = null;

                break;
            } catch (ProviderRateLimited|ProviderUnavailable|ProviderBadResponse $exception) {
                $failure = $exception;
                $last = $position === count($chain) - 1;
                $failsOver = ! ($exception instanceof ProviderBadResponse) || $exception->failsOver;

                if ($exception->reachedProvider()) {
                    $latencyMs = $this->countCall($feature, $actor, $started);
                }

                if ($exception instanceof ProviderRateLimited) {
                    $this->cooldown->start($name, $exception->retryAfterSeconds);
                }

                $this->logFailure($feature, $name, $exception, $failsOver && ! $last);

                if (! $failsOver) {
                    break;
                }

                // The failed call keeps a row of its own when it counts, so the daily limits see both calls
                if (! $last && $latencyMs !== null) {
                    $failedCalls[] = ['provider' => $provider, 'latency_ms' => $latencyMs, 'outcome' => self::failureOutcome($exception)];
                }
            }
        }

        return new ProviderCall($current, $response, $latencyMs, $fallbackFrom, $failedCalls, $failure, $request);
    }

    public static function failureOutcome(ProviderFailure $failure): AssistantOutcome
    {
        return $failure instanceof ProviderBadResponse ? AssistantOutcome::FallbackError : AssistantOutcome::FallbackUnavailable;
    }

    /**
     * THE counting rule: a call counts toward every limit (the per-minute limiter now, and the daily limits and budget
     * through the latency_ms of its assistant_runs row) only once the provider sent an HTTP response. A call that never
     * got one (no key, no provider, a connection, certificate or timeout error) counts toward nothing.
     */
    private function countCall(AssistantFeature $feature, AssistantActor $actor, int $started): int
    {
        $this->limits->hit($actor, $feature);

        return (int) round((hrtime(true) - $started) / 1_000_000);
    }

    /** The provider, the kind, HTTP status, cURL error number and reason say why; never the body, prompt, message, key or URL. */
    private function logFailure(AssistantFeature $feature, string $provider, ProviderFailure $cause, bool $failingOver): void
    {
        Log::warning('assistant.provider_failed', [
            'feature' => $feature->value,
            'provider' => $provider,
            'outcome' => self::failureOutcome($cause)->value,
            'exception' => $cause::class,
            'failover' => $failingOver,
        ] + $cause->logContext());
    }
}
