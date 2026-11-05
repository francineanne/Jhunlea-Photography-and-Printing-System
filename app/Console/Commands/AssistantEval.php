<?php

namespace App\Console\Commands;

use App\Services\Assistant\Eval\CaseBank;
use App\Services\Assistant\Eval\EvalAttempt;
use App\Services\Assistant\Eval\EvalCase;
use App\Services\Assistant\Eval\EvalReport;
use App\Services\Assistant\Eval\EvalRunner;
use App\Services\Assistant\Eval\EvalSandbox;
use App\Services\Assistant\Providers\ProviderResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

/**
 * The Lumi evaluation (Phase 7E-2b). Runs the written cases of database/evals/cases through the real AssistantEngine
 * in a throwaway SQLite sandbox (EvalSandbox: never the real database) with fake customers and a frozen clock, checks
 * every reply deterministically, prints a report, saves it as .md and .json under storage/app/evals, and exits 1 when
 * the quality gate fails.
 *
 * Without --live only the fake provider is used (no network, no cost): that run tests the harness, not Lumi. --live
 * uses the real provider and needs --yes; without --yes it only prints how many model calls the run would make and
 * an estimated cost. It never prints or stores a key or a prompt.
 */
#[Signature('assistant:eval
    {--provider= : anthropic, gemini or fake (default: the configured main provider when --live, else fake)}
    {--fallback=none : The backup provider, or none to measure one provider alone}
    {--category=* : Only these categories}
    {--case=* : Only these case ids}
    {--runs=1 : Run every model case this many times (flakiness)}
    {--limit= : The most model calls to make}
    {--delay=0 : Seconds to wait after each model call}
    {--live : Use the real provider (costs money)}
    {--yes : Confirm a live run}
    {--compare= : Two or more providers, comma separated, run on the same cases}
    {--report= : The report file name (saved in storage/app/evals)}
    {--list : List the cases and stop}')]
#[Description('Evaluate the AI assistant Lumi on the written cases (fake provider unless --live --yes)')]
class AssistantEval extends Command
{
    /** Phase 7E-2c: too many provider failures or skipped cases to judge (0 passed, 1 failed). */
    public const EXIT_INCONCLUSIVE = 2;

    public function handle(CaseBank $bank, ProviderResolver $resolver): int
    {
        if ($this->laravel->environment('production')) {
            $this->error('assistant:eval is a development tool and does not run in production.');

            return self::FAILURE;
        }

        try {
            $cases = $this->select($bank->load());
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($cases === []) {
            $this->error('No case matches --category / --case.');

            return self::FAILURE;
        }

        if ($this->option('list')) {
            return $this->list($cases);
        }

        $runs = filter_var($this->option('runs'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 20]]);
        $limit = $this->option('limit') === null ? null : filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $delay = filter_var($this->option('delay'), FILTER_VALIDATE_FLOAT);

        if ($runs === false || $limit === false || $delay === false || $delay < 0 || $delay > 60) {
            $this->error('--runs must be 1 to 20, --limit a whole number of 0 or more, --delay 0 to 60 seconds.');

            return self::FAILURE;
        }

        $live = (bool) $this->option('live');
        $providers = $this->providers($live);

        if ($providers === null) {
            return self::FAILURE;
        }

        $fallback = strtolower(trim((string) $this->option('fallback')));
        $fallback = in_array($fallback, ['', 'none'], true) ? null : $fallback;

        if ($this->option('compare') && $fallback !== null) {
            $this->warn('--compare measures each provider alone: --fallback is ignored.');
            $fallback = null;
        }

        $real = array_values(array_filter($providers, fn (string $name) => $name !== 'fake'));

        if ($real !== []) {
            foreach ([...$real, ...($fallback === null ? [] : [$fallback])] as $name) {
                if ($resolver->make($name) === null) {
                    $this->error("The provider \"{$name}\" is unknown or has no key configured.");

                    return self::FAILURE;
                }
            }

            if (! $this->option('yes')) {
                return $this->dryRun($cases, $real, $runs, $limit);
            }
        }

        $reports = [];
        $statuses = [];

        foreach ($providers as $provider) {
            $report = $this->runOne($cases, $provider, $provider === 'fake' ? null : $fallback, $runs, $limit, $provider === 'fake' ? 0.0 : (float) $delay, $live && $provider !== 'fake');
            $reports[$provider] = $report;
            $statuses[] = $report->gate()['status'];
        }

        // 1 when any run failed the gate (a must_never failure always fails it), else 2 when any run was inconclusive
        $exit = match (true) {
            in_array(EvalReport::FAILED, $statuses, true) => self::FAILURE,
            in_array(EvalReport::INCONCLUSIVE, $statuses, true) => self::EXIT_INCONCLUSIVE,
            default => self::SUCCESS,
        };

        if (count($reports) > 1) {
            $comparison = EvalReport::compare($reports);
            $this->newLine();
            $this->print($comparison);
            $path = $this->save($this->baseName('compare'), $comparison, null);
            $this->info("Comparison saved: {$path}");
        }

        return $exit;
    }

    /** @return list<string>|null the providers to run, in order */
    private function providers(bool $live): ?array
    {
        $compare = trim((string) $this->option('compare'));

        if ($compare !== '') {
            $names = array_values(array_unique(array_filter(array_map(fn ($name) => strtolower(trim($name)), explode(',', $compare)))));

            if (count($names) < 2) {
                $this->error('--compare needs two or more providers, for example --compare=anthropic,gemini');

                return null;
            }

            if (! $live && array_diff($names, ['fake']) !== []) {
                $this->error('--compare with a real provider needs --live (and --yes to really run).');

                return null;
            }

            return $names;
        }

        $provider = strtolower(trim((string) ($this->option('provider') ?? '')));

        if (! $live) {
            if ($provider !== '' && $provider !== 'fake') {
                $this->warn("Not live: the fake provider is used instead of \"{$provider}\". Add --live --yes to call {$provider}.");
            }

            return ['fake'];
        }

        return [$provider !== '' ? $provider : (string) config('assistant.provider')];
    }

    /**
     * @param  list<EvalCase>  $cases
     * @param  list<string>  $providers
     */
    private function dryRun(array $cases, array $providers, int $runs, ?int $limit): int
    {
        $calls = array_sum(array_map(fn (EvalCase $case) => $case->isModelCase() ? $runs : 0, $cases));
        $calls = $limit === null ? $calls : min($calls, $limit);
        $per = (array) config('assistant.eval.estimate_tokens_per_call');

        $this->warn('Dry run: nothing was sent. Add --yes to run it for real.');

        foreach ($providers as $provider) {
            $model = $provider === 'anthropic' ? (string) config('assistant.anthropic.model') : ($provider === 'gemini' ? (string) config('assistant.model') : $provider);
            $price = ((array) config('assistant.pricing.models', []))[$model] ?? null;

            // Only Claude reads the stable prefix from its prompt cache; every other provider gets it all as input
            $tokens = $provider === 'anthropic' ? $per
                : ['input' => (int) ($per['input'] ?? 0) + (int) ($per['cache_read'] ?? 0), 'output' => (int) ($per['output'] ?? 0), 'cache_read' => 0, 'cache_write' => 0];
            $usd = is_array($price) ? $calls * ((int) ($tokens['input'] ?? 0) * (float) $price['input'] + (int) ($tokens['output'] ?? 0) * (float) $price['output']
                + (int) ($tokens['cache_read'] ?? 0) * (float) ($price['cache_read'] ?? 0) + (int) ($tokens['cache_write'] ?? 0) * (float) ($price['cache_write'] ?? 0)) / 1_000_000 : null;

            $this->line("{$provider} ({$model}): about {$calls} model call(s); estimated cost: ".($usd === null
                ? 'unknown (no price for this model in config assistant.pricing)'
                : '$'.number_format($usd, 4).' (about ₱'.number_format($usd * (float) config('assistant.pricing.usd_to_php'), 2).')').' (estimate, verify prices)');
        }

        return self::SUCCESS;
    }

    /** @param list<EvalCase> $cases */
    private function runOne(array $cases, string $provider, ?string $fallback, int $runs, ?int $limit, float $delay, bool $live): EvalReport
    {
        $saved = ['assistant.provider' => config('assistant.provider'), 'assistant.fallback_provider' => config('assistant.fallback_provider'), 'assistant.enabled' => config('assistant.enabled')];
        config(['assistant.provider' => $provider, 'assistant.fallback_provider' => $fallback, 'assistant.enabled' => true]);
        $startedAt = date('Y-m-d H:i:s');

        $this->info('Running '.count($cases)." case(s) with {$provider}".($fallback ? " (fallback {$fallback})" : '').", runs: {$runs}".($limit === null ? '' : ", limit: {$limit} call(s)"));
        $bar = $this->output->createProgressBar(count($cases) * $runs);

        try {
            $attempts = app(EvalRunner::class)->onProgress(fn (EvalAttempt $attempt) => $bar->advance())
                ->run($cases, new EvalSandbox((string) (config('assistant.eval.database') ?: EvalSandbox::defaultPath())), $runs, $limit, $delay);
        } catch (RuntimeException $exception) {
            $bar->finish();
            config($saved);
            $this->newLine();
            $this->error($exception->getMessage());

            return new EvalReport($provider, $fallback, [], $runs, $live, $startedAt);
        }

        $bar->finish();
        $this->newLine(2);
        config($saved);

        $report = new EvalReport($provider, $fallback, $attempts, $runs, $live, $startedAt);
        $markdown = $report->markdown();
        $this->print($markdown);

        $path = $this->save($this->baseName($provider), $markdown, $report->toArray());
        $this->info("Report saved: {$path} (.md and .json)");
        $gate = $report->gate();
        match ($gate['status']) {
            EvalReport::PASSED => $this->info('Quality gate PASSED.'),
            EvalReport::INCONCLUSIVE => $this->warn('Quality gate INCONCLUSIVE (exit code 2): '.implode('; ', $gate['reasons'])),
            default => $this->error('Quality gate FAILED: '.implode('; ', $gate['reasons'])),
        };

        return $report;
    }

    private function print(string $markdown): void
    {
        foreach (explode("\n", rtrim($markdown)) as $line) {
            $this->line($line);
        }
    }

    /** @param list<EvalCase> $cases */
    private function list(array $cases): int
    {
        $rows = array_map(fn (EvalCase $case) => [$case->id, $case->category, $case->severity, $case->scenario, $case->feature, $case->isReview() ? 'review' : ($case->isModelCase() ? 'model' : 'no model'), mb_strimwidth($case->message, 0, 60, '…')], $cases);
        $this->table(['Id', 'Category', 'Severity', 'Scenario', 'Feature', 'Kind', 'Message'], $rows);

        $counts = array_count_values(array_map(fn (EvalCase $case) => $case->category, $cases));
        $this->line(count($cases).' cases; '.count(array_filter($cases, fn (EvalCase $case) => $case->isMustNever())).' must_never; '
            .count(array_filter($cases, fn (EvalCase $case) => $case->isReview())).' review; '
            .count(array_filter($cases, fn (EvalCase $case) => $case->isModelCase())).' model cases.');
        $this->line('By category: '.implode(', ', array_map(fn ($category, $count) => "{$category} {$count}", array_keys($counts), $counts)));

        return self::SUCCESS;
    }

    /**
     * @param  list<EvalCase>  $cases
     * @return list<EvalCase>
     */
    private function select(array $cases): array
    {
        $categories = array_filter((array) $this->option('category'));
        $ids = array_filter((array) $this->option('case'));

        return array_values(array_filter($cases, fn (EvalCase $case) => ($categories === [] || in_array($case->category, $categories, true))
            && ($ids === [] || in_array($case->id, $ids, true))));
    }

    private function baseName(string $provider): string
    {
        $name = trim((string) $this->option('report'));
        $name = $name === '' ? date('Ymd-His').'-'.$provider : $name.(count(explode(',', (string) $this->option('compare'))) > 1 ? '-'.$provider : '');

        return (string) preg_replace('/[^A-Za-z0-9._-]/', '-', $name);
    }

    /** @param array<string, mixed>|null $json */
    private function save(string $base, string $markdown, ?array $json): string
    {
        $directory = storage_path('app/evals');

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents("{$directory}/{$base}.md", $markdown);

        if ($json !== null) {
            file_put_contents("{$directory}/{$base}.json", json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return "storage/app/evals/{$base}.md";
    }
}
