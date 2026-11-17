<?php

namespace App\Services\Assistant;

use App\Enums\AssistantOutcome;
use App\Models\AssistantRun;
use App\Support\LocalTime;
use App\Support\Lumi;
use Illuminate\Support\Carbon;

/**
 * Phase 10E: how Lumi is doing, for the owner's Settings > Lumi assistant. Read only, from assistant_runs metadata
 * (never a message, a prompt, a key or an error text), in a fixed handful of queries.
 */
class LumiStatus
{
    public const WORKING = 'working';

    public const BACKUP = 'backup';

    public const DOWN = 'down';

    public const OFF = 'off';

    private const ANSWERED = [AssistantOutcome::Answered, AssistantOutcome::Handoff, AssistantOutcome::Refused];

    private const PROVIDER_FAILED = [AssistantOutcome::FallbackUnavailable, AssistantOutcome::FallbackError];

    public function __construct(private readonly LimitService $limits) {}

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $today = $this->limits->dayStartUtc();
        $answered = array_map(fn (AssistantOutcome $outcome) => $outcome->value, self::ANSWERED);
        $failed = array_map(fn (AssistantOutcome $outcome) => $outcome->value, self::PROVIDER_FAILED);

        $last = AssistantRun::query()->whereIn('outcome', $answered)->latest('id')->first(['id', 'provider', 'fallback_from', 'created_at']);
        $counts = AssistantRun::query()->where('created_at', '>=', $today)->toBase()->selectRaw(
            'SUM(CASE WHEN outcome IN ('.implode(',', array_fill(0, count($answered), '?')).') THEN 1 ELSE 0 END) as answers, '
            .'SUM(CASE WHEN outcome LIKE ? THEN 1 ELSE 0 END) as fallbacks, '
            .'SUM(CASE WHEN outcome = ? THEN 1 ELSE 0 END) as handoffs, '
            .'SUM(CASE WHEN latency_ms IS NOT NULL THEN 1 ELSE 0 END) as calls',
            [...$answered, 'fallback%', AssistantOutcome::Handoff->value],
        )->first();
        $errorsLastHour = AssistantRun::query()->where('created_at', '>=', now()->subHour())->whereIn('outcome', $failed)->count();
        // The newest few answers or provider failures (a connection failure has no latency) say whether the main provider or the backup is answering now
        $recent = AssistantRun::query()->where('created_at', '>=', now()->subHour())->whereIn('outcome', [...$answered, ...$failed])->latest('id')->limit(5)->get(['outcome', 'fallback_from']);

        $budget = (int) config('assistant.limits.global_daily_budget');
        $state = match (true) {
            ! config('assistant.enabled') || Lumi::offInSettings() => self::OFF,
            $recent->isNotEmpty() && $recent->every(fn (AssistantRun $run) => in_array($run->outcome, self::PROVIDER_FAILED, true)) => self::DOWN,
            $recent->contains(fn (AssistantRun $run) => $run->fallback_from !== null && in_array($run->outcome, self::ANSWERED, true)) => self::BACKUP,
            default => self::WORKING,
        };

        return [
            'state' => $state,
            'off_in_settings' => Lumi::offInSettings(),
            'off_on_server' => ! config('assistant.enabled'),
            'last_answer_at' => $last?->created_at === null ? null : LocalTime::format($last->created_at),
            'last_answer_ago' => $last?->created_at?->diffForHumans(),
            'last_answer_by' => $last === null ? null : self::providerName($last->provider, $last->fallback_from !== null),
            'answers_today' => (int) ($counts->answers ?? 0),
            'fallbacks_today' => (int) ($counts->fallbacks ?? 0),
            'handoffs_today' => (int) ($counts->handoffs ?? 0),
            'errors_last_hour' => $errorsLastHour,
            'calls_today' => (int) ($counts->calls ?? 0),
            'budget' => $budget,
            'budget_percent' => $budget > 0 ? min(100, (int) round(100 * (int) ($counts->calls ?? 0) / $budget)) : 0,
        ];
    }

    /** "Claude", "Gemini (Lumi Lite)", "Fake (test provider)", "Answered by code": never a model id or a key. */
    public static function providerName(?string $provider, bool $viaBackup = false): string
    {
        $name = match (strtolower((string) $provider)) {
            'anthropic', 'claude' => 'Claude',
            'gemini', 'google' => 'Gemini',
            'fake' => 'Fake (test provider)',
            'code' => 'Answered by code',
            default => $provider === null || $provider === '' ? 'Unknown' : ucfirst((string) $provider),
        };

        return $viaBackup ? $name.' (Lumi Lite)' : $name;
    }

    /** One plain word for the state badge. */
    public static function label(string $state): string
    {
        return match ($state) {
            self::WORKING => 'Working',
            self::BACKUP => 'Using backup (Gemini)',
            self::DOWN => 'Down: customers see the fallback message',
            default => 'Turned off',
        };
    }

    /** The question the "Test Lumi" button sends: short, about the catalog, never answered by code. */
    public const TEST_QUESTION = 'In one short sentence, what kinds of photography packages does the studio offer?';

    public static function since(Carbon $start): int
    {
        return (int) round($start->diffInMilliseconds(now()));
    }
}
