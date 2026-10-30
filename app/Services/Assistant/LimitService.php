<?php

namespace App\Services\Assistant;

use App\Enums\AssistantFeature;
use App\Enums\AssistantOutcome;
use App\Models\AssistantRun;
use App\Support\LocalTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Decides whether one more call may reach the provider. The per-minute limit uses Laravel's RateLimiter under its own
 * name; the daily limits and the shop's daily budget are counted from assistant_runs rows the provider answered (see
 * AssistantEngine::countCall), for the current Manila day (midnight to midnight, Asia/Manila; rows are stored in UTC).
 */
class LimitService
{
    public const MINUTE_LIMITER = 'assistant-minute';

    /** Null when the call may go ahead, otherwise the fallback outcome to answer with. */
    public function check(AssistantFeature $feature, AssistantActor $actor): ?AssistantOutcome
    {
        $limits = config('assistant.limits');

        if (RateLimiter::tooManyAttempts($this->minuteKey($actor), (int) $limits['per_minute_per_user'])) {
            return AssistantOutcome::FallbackLimitUser;
        }

        $today = AssistantRun::query()->calledProvider()->where('created_at', '>=', $this->dayStartUtc());

        $own = $actor->isGuest()
            ? (clone $today)->where('guest_key', $actor->guestKey)->count() >= (int) $limits['per_day_per_guest_ip']
            : (clone $today)->where('user_id', $actor->userId)->count() >= (int) $limits['per_day_per_user'];

        if ($own) {
            return AssistantOutcome::FallbackLimitUser;
        }

        $budget = (int) $limits['global_daily_budget'];
        $share = (float) ($limits['global_shares'][$feature->budgetGroup()] ?? 0);
        $group = array_map(fn (AssistantFeature $item) => $item->value, AssistantFeature::inGroup($feature->budgetGroup()));

        if ((clone $today)->count() >= $budget || (clone $today)->whereIn('feature', $group)->count() >= (int) floor($budget * $share)) {
            return AssistantOutcome::FallbackLimitGlobal;
        }

        return null;
    }

    /** Counts one call against the per-minute limit, once the provider has answered it. */
    public function hit(AssistantActor $actor): void
    {
        RateLimiter::hit($this->minuteKey($actor), 60);
    }

    /** The start of today in Manila, in UTC like the stored rows. */
    public function dayStartUtc(): Carbon
    {
        return LocalTime::dayStartUtc(Carbon::now(config('app.display_timezone'))->toDateString());
    }

    private function minuteKey(AssistantActor $actor): string
    {
        return self::MINUTE_LIMITER.':'.$actor->key();
    }
}
