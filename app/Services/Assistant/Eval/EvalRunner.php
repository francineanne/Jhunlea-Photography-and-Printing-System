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
use App\Services\Assistant\PriceGuard;
use App\Services\Assistant\PromptBuilder;
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
    /** @var (callable(EvalAttempt): void)|null */
    private $progress = null;

    public function __construct(
        private readonly EvalChecks $checks,
        private readonly PriceGuard $prices,
    ) {}

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

            $recorder = app(RecordingPromptBuilder::class);
            app()->instance(PromptBuilder::class, $recorder);

            for ($run = 1; $run <= max(1, $runs); $run++) {
                foreach ($cases as $case) {
                    // Answers by code and the ClaimGuard cases are deterministic: one run is enough
                    if ($run > 1 && ! $case->isModelCase()) {
                        continue;
                    }

                    $calls = AssistantRun::query()->whereNotNull('latency_ms')->count();

                    if ($limit !== null && $case->isModelCase() && $calls >= $limit) {
                        $attempt = new EvalAttempt($case, $run, EvalAttempt::SKIPPED);
                    } else {
                        $attempt = $this->attempt($case, $run, $sandbox, $fixtures, $recorder);

                        if ($delay > 0 && $attempt->providerCalled) {
                            usleep((int) round($delay * 1_000_000));
                        }
                    }

                    $attempts[] = $attempt;

                    if ($this->progress !== null) {
                        ($this->progress)($attempt);
                    }
                }
            }
        } finally {
            app()->forgetInstance(PromptBuilder::class);
            $sandbox->close();
        }

        return $attempts;
    }

    private function attempt(EvalCase $raw, int $run, EvalSandbox $sandbox, EvalFixtures $fixtures, RecordingPromptBuilder $recorder): EvalAttempt
    {
        try {
            $sandbox->assertActive();
            $sandbox->freeze($raw->now ?? EvalSandbox::FROZEN_NOW);
            $case = $fixtures->resolve($raw);
            $before = (int) AssistantRun::query()->max('id');
            $recorder->last = null;

            $seen = $case->feature === 'claimguard' ? $this->guardOnly($case, $fixtures) : $this->engine($case, $fixtures, $recorder);

            $rows = AssistantRun::query()->where('id', '>', $before)->orderBy('id')->get();
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

    /** The real ClaimGuard on the case's fixed reply, with the scenario customer's real records. No model call. */
    private function guardOnly(EvalCase $case, EvalFixtures $fixtures): EvalObservation
    {
        $records = $case->scenario === 'guest' ? CustomerRecords::none() : app(CustomerContextBuilder::class)->build($fixtures->customer($case->scenario));
        $reply = app(ClaimGuard::class)->guard((string) $case->reply, $records);

        return new EvalObservation($reply, 'guarded', null, [], false, null, false);
    }
}
