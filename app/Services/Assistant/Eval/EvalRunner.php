<?php

namespace App\Services\Assistant\Eval;

use App\Enums\AssistantAuthor;
use App\Enums\AssistantFeature;
use App\Enums\AssistantOutcome;
use App\Models\AssistantRun;
use App\Services\Assistant\AssistantEngine;
use App\Services\Assistant\AssistantInput;
use App\Services\Assistant\ClaimGuard;
use App\Services\Assistant\CustomerContextBuilder;
use App\Services\Assistant\CustomerRecords;
use App\Services\Assistant\GuardStats;
use App\Services\Assistant\Owner\OwnerAskService;
use App\Services\Assistant\Owner\OwnerPromptBuilder;
use App\Services\Assistant\PriceGuard;
use App\Services\Assistant\PromptBuilder;
use App\Services\Assistant\ProviderCooldown;
use App\Services\Assistant\ResponseValidator;
use App\Services\Assistant\ThreadMessage;
use Throwable;

/**
 * Runs eval cases through the PRODUCTION path (Phase 7E-2b): AssistantEngine::handle() with the real feature, a real
 * AssistantActor for the fake customer or a guest, and the case's history, in the sandbox with a frozen clock. Feature
 * "claimguard" runs the real ClaimGuard on a fixed reply with no model call. The provider is whatever config says when
 * run() is called (the command sets it). The assistant_runs rows each case writes in the sandbox give its latency,
 * tokens, cache reads and failovers.
 */
class EvalRunner
{
    /** Phase 7E-2c: the longest wait before retrying a case after a 429, and the wait when the provider named none. */
    public const RETRY_MAX_SECONDS = 60;

    public const RETRY_DEFAULT_SECONDS = 15;

    private ?RecordingResponseValidator $validator = null;

    private ?RecordingOwnerPromptBuilder $ownerPrompts = null;

    /** @var (callable(EvalAttempt): void)|null */
    private $progress = null;

    /** @var callable(float): void sleeps this many seconds; tests replace it so nothing really waits */
    private $waiter;

    public function __construct(
        private readonly EvalChecks $checks,
        private readonly PriceGuard $prices,
    ) {
        $this->waiter = fn (float $seconds) => usleep((int) round($seconds * 1_000_000));
    }

    /** @param callable(float): void $waiter */
    public function useWaiter(callable $waiter): static
    {
        $this->waiter = $waiter;

        return $this;
    }

    /** @param callable(EvalAttempt): void $progress called after each attempt */
    public function onProgress(callable $progress): static
    {
        $this->progress = $progress;

        return $this;
    }

    /**
     * @param  list<EvalCase>  $cases
     * @return list<EvalAttempt>
     */
    public function run(array $cases, EvalSandbox $sandbox, int $runs = 1, ?int $limit = null, float $delay = 0.0): array
    {
        $attempts = [];

        try {
            $sandbox->open();
            $fixtures = new EvalFixtures;
            $fixtures->build($sandbox);
            // The fixtures hold a few Lumi runs of their own (7E-3a): --limit counts only the calls of this run
            $baseline = AssistantRun::query()->whereNotNull('latency_ms')->count();

            $recorder = app(RecordingPromptBuilder::class);
            app()->instance(PromptBuilder::class, $recorder);
            $cooldown = new EvalCooldown;
            app()->instance(ProviderCooldown::class, $cooldown);
            // 7E-2e: keeps the raw reply in memory for the character diagnostic
            $this->validator = app(RecordingResponseValidator::class);
            app()->instance(ResponseValidator::class, $this->validator);
            // 7E-3a: keeps the owner's tool facts and prompt in memory for the owner checks
            $this->ownerPrompts = app(RecordingOwnerPromptBuilder::class);
            app()->instance(OwnerPromptBuilder::class, $this->ownerPrompts);

            for ($run = 1; $run <= max(1, $runs); $run++) {
                foreach ($cases as $case) {
                    // Answers by code and the ClaimGuard cases are deterministic: one run is enough
                    if ($run > 1 && ! $case->isModelCase()) {
                        continue;
                    }

                    $calls = AssistantRun::query()->whereNotNull('latency_ms')->count() - $baseline;

                    if ($limit !== null && $case->isModelCase() && $calls >= $limit) {
                        $attempt = new EvalAttempt($case, $run, EvalAttempt::SKIPPED);
                    } else {
                        $attempt = $this->tryCase($case, $run, $sandbox, $fixtures, $recorder, $cooldown, $delay);
                    }

                    $attempts[] = $attempt;

                    if ($this->progress !== null) {
                        ($this->progress)($attempt);
                    }
                }
            }
        } finally {
            app()->forgetInstance(PromptBuilder::class);
            app()->forgetInstance(ProviderCooldown::class);
            app()->forgetInstance(ResponseValidator::class);
            app()->forgetInstance(OwnerPromptBuilder::class);
            $sandbox->close();
        }

        return $attempts;
    }

    /**
     * One case, retried ONCE after a 429 (Phase 7E-2c): wait the provider's retry-after (at most RETRY_MAX_SECONDS,
     * RETRY_DEFAULT_SECONDS when it named none), clear that provider's cooldown in the sandbox so the retry really asks
     * it, and try again; a second failure stays provider_failed. The cooldown is cleared after the case too, so one 429
     * never fails every case after it. --delay applies after every provider call, the retry included.
     */
    private function tryCase(EvalCase $case, int $run, EvalSandbox $sandbox, EvalFixtures $fixtures, RecordingPromptBuilder $recorder, EvalCooldown $cooldown, float $delay): EvalAttempt
    {
        $cooldown->lastRateLimit = null;
        $attempt = $this->attempt($case, $run, $sandbox, $fixtures, $recorder);
        $this->pause($delay, $attempt);
        $retries = 0;

        if ($attempt->status === EvalAttempt::PROVIDER_FAILED && $cooldown->lastRateLimit !== null) {
            $limited = $cooldown->lastRateLimit;
            ($this->waiter)((float) min(self::RETRY_MAX_SECONDS, $limited['seconds'] ?? self::RETRY_DEFAULT_SECONDS));
            $cooldown->clear($limited['provider']);
            $cooldown->lastRateLimit = null;
            $retries = 1;

            $attempt = $this->attempt($case, $run, $sandbox, $fixtures, $recorder);
            $this->pause($delay, $attempt);
        }

        if ($cooldown->lastRateLimit !== null) {
            $cooldown->clear($cooldown->lastRateLimit['provider']);
        }

        return $attempt->withRetries($retries);
    }

    private function pause(float $delay, EvalAttempt $attempt): void
    {
        if ($delay > 0 && ($attempt->providerCalled || $attempt->status === EvalAttempt::PROVIDER_FAILED)) {
            ($this->waiter)($delay);
        }
    }

    private function attempt(EvalCase $raw, int $run, EvalSandbox $sandbox, EvalFixtures $fixtures, RecordingPromptBuilder $recorder): EvalAttempt
    {
        try {
            $sandbox->assertActive();
            $sandbox->freeze($raw->now ?? EvalSandbox::FROZEN_NOW);
            $case = $fixtures->resolve($raw);
            $before = (int) AssistantRun::query()->max('id');
            $recorder->last = null;
            if ($this->validator !== null) {
                $this->validator->raw = null;
            }
            $stats = app(GuardStats::class);
            $stats->reset();

            $seen = match ($case->feature) {
                'claimguard' => $this->guardOnly($case, $fixtures),
                'owner_ask' => $this->owner($case, $fixtures),
                default => $this->engine($case, $fixtures, $recorder),
            };

            $rows = AssistantRun::query()->where('id', '>', $before)->orderBy('id')->get();

            if ($case->feature === 'owner_ask') {
                $tools = $rows->pluck('tools')->filter()->flatMap(fn (string $names) => explode(',', $names))->unique()->values()->all();
                $seen = new EvalObservation($seen->reply, $seen->outcome, $seen->intent, $seen->cards, false, null, $seen->providerCalled, $seen->prompt, $tools, $seen->facts);
            }
            $called = $rows->whereNotNull('latency_ms');
            $usage = [];

            foreach ($called as $row) {
                $model = (string) $row->model;
                $usage[$model] ??= ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0];
                $usage[$model]['input'] += (int) $row->input_tokens;
                $usage[$model]['output'] += (int) $row->output_tokens;
                $usage[$model]['cache_read'] += (int) $row->cache_read_tokens;
                $usage[$model]['cache_write'] += (int) $row->cache_write_tokens;
            }

            $common = [
                'reply' => $seen->reply,
                'outcome' => $seen->outcome,
                'providerCalled' => $seen->providerCalled,
                'latencies' => $called->pluck('latency_ms')->map(fn ($ms) => (int) $ms)->values()->all(),
                'usage' => $usage,
                'providers' => $called->pluck('provider')->unique()->values()->all(),
                'failovers' => $rows->whereNotNull('fallback_from')->count(),
                'characters' => $seen->providerCalled && $case->feature !== 'claimguard'
                    ? EvalCharacters::describe($this->validator?->raw, $seen->reply, $this->checks->gluedWords($seen->reply)) : null,
                'guards' => $stats->snapshot(),
            ];

            if ($seen->outcome === AssistantOutcome::FallbackUnavailable->value) {
                return new EvalAttempt($case, $run, EvalAttempt::PROVIDER_FAILED, ['provider: the provider did not answer (429, 5xx, timeout or no key)'], ...$common);
            }

            if ($case->isReview()) {
                return new EvalAttempt($case, $run, EvalAttempt::REVIEW, [], ...$common);
            }

            $failed = $this->checks->run($case, $seen, $fixtures->context($case, $this->prices));

            return new EvalAttempt($case, $run, $failed === [] ? EvalAttempt::PASS : EvalAttempt::FAIL, $failed, ...$common);
        } catch (Throwable $exception) {
            // The harness's own messages (a case file mistake) are safe to show; anything else only by its kind, since its
            // message could hold data
            $own = str_starts_with(str_replace('\\', '/', $exception->getFile()), str_replace('\\', '/', app_path('Services/Assistant/Eval')));

            return new EvalAttempt($raw, $run, EvalAttempt::ERROR, ['harness: '.class_basename($exception).($own ? ': '.$exception->getMessage() : '').' @'.basename($exception->getFile()).':'.$exception->getLine()]);
        }
    }

    private function engine(EvalCase $case, EvalFixtures $fixtures, RecordingPromptBuilder $recorder): EvalObservation
    {
        $history = array_map(fn (array $turn) => new ThreadMessage(match ($turn['role']) {
            'customer' => AssistantAuthor::Customer,
            'owner' => AssistantAuthor::Owner,
            default => AssistantAuthor::Lumi,
        }, $turn['text']), $case->history);

        $feature = $case->feature === 'studio_away' ? AssistantFeature::StudioAway : AssistantFeature::AskLumi;
        $result = app(AssistantEngine::class)->handle(new AssistantInput($feature, $fixtures->actor($case->scenario), $case->message, $history));

        return new EvalObservation(
            $result->reply,
            $result->outcome->value,
            $result->intent?->value,
            $result->cards,
            $result->needsOwner,
            $result->about,
            ! in_array($result->outcome, [AssistantOutcome::AnsweredByCode, AssistantOutcome::RejectedInput, AssistantOutcome::FallbackDisabled], true),
            $recorder->last?->promptText(),
        );
    }

    /** An owner case through the real OwnerAskService, with the owner as the actor (7E-3a). */
    private function owner(EvalCase $case, EvalFixtures $fixtures): EvalObservation
    {
        $this->ownerPrompts->results = [];
        $this->ownerPrompts->last = null;
        $history = array_map(fn (array $turn) => new ThreadMessage($turn['role'] === 'lumi' ? AssistantAuthor::Lumi : AssistantAuthor::Customer, $turn['text']), $case->history);
        $result = app(OwnerAskService::class)->ask($fixtures->owner(), $case->message, $history);

        return new EvalObservation(
            $result->reply,
            $result->outcome->value,
            null,
            array_map(fn (array $card) => ['type' => 'owner', 'id' => 0, 'name' => $card['label'], 'url' => $card['url']], $result->cards),
            false,
            null,
            ! in_array($result->outcome, [AssistantOutcome::RejectedInput, AssistantOutcome::FallbackDisabled, AssistantOutcome::AnsweredByCode], true),
            $this->ownerPrompts->last?->promptText(),
            $result->tools,
            $this->ownerPrompts->facts(),
        );
    }

    /** The real ClaimGuard on the case's fixed reply, with the scenario customer's real records. No model call. */
    private function guardOnly(EvalCase $case, EvalFixtures $fixtures): EvalObservation
    {
        $records = $case->scenario === 'guest' ? CustomerRecords::none() : app(CustomerContextBuilder::class)->build($fixtures->customer($case->scenario));
        $reply = app(ClaimGuard::class)->guard((string) $case->reply, $records);

        return new EvalObservation($reply, 'guarded', null, [], false, null, false);
    }
}
