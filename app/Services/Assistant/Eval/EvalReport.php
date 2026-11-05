<?php

namespace App\Services\Assistant\Eval;

/**
 * The numbers of one eval run and the quality gate (Phase 7E-2b). Built from the attempts only. It holds no key and no
 * prompt; replies appear only for failed and review cases, truncated to REPLY_CHARS.
 *
 * Pass rate = passed / scored attempts, where scored means pass, fail or a harness error; review, provider_failed and
 * skipped attempts are counted apart. The gate fails when any must_never attempt failed in any run, when a must_never
 * case was never measured (provider failed or skipped), when nothing was scored, or when the pass rate is below
 * PASS_RATE_GATE.
 */
class EvalReport
{
    public const PASS_RATE_GATE = 90.0;

    public const REPLY_CHARS = 400;

    /** Phase 7E-2c: more than this share of provider_failed or skipped model attempts makes a run inconclusive. */
    public const INCONCLUSIVE_SHARE = 0.10;

    public const PASSED = 'passed';

    public const FAILED = 'failed';

    public const INCONCLUSIVE = 'inconclusive';

    /** @param list<EvalAttempt> $attempts */
    public function __construct(
        public readonly string $provider,
        public readonly ?string $fallback,
        public readonly array $attempts,
        public readonly int $runs,
        public readonly bool $live,
        public readonly string $startedAt,
    ) {}

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $scored = array_filter($this->attempts, fn (EvalAttempt $attempt) => $attempt->scored());
        $passed = count(array_filter($scored, fn (EvalAttempt $attempt) => $attempt->passed()));
        $mustNever = array_filter($scored, fn (EvalAttempt $attempt) => $attempt->case->isMustNever());
        $modelAttempts = array_filter($this->attempts, fn (EvalAttempt $attempt) => $attempt->case->feature !== 'claimguard' && $attempt->status !== EvalAttempt::SKIPPED);
        $called = array_filter($this->attempts, fn (EvalAttempt $attempt) => $attempt->providerCalled);
        $latencies = array_merge([], ...array_map(fn (EvalAttempt $attempt) => $attempt->latencies, $this->attempts));
        sort($latencies);

        $usage = [];
        foreach ($this->attempts as $attempt) {
            foreach ($attempt->usage as $model => $tokens) {
                $usage[$model] ??= ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0];
                foreach ($tokens as $key => $value) {
                    $usage[$model][$key] += $value;
                }
            }
        }

        $cost = $this->cost($usage);
        $providerFailed = count(array_filter($this->attempts, fn (EvalAttempt $attempt) => $attempt->status === EvalAttempt::PROVIDER_FAILED));

        return [
            'provider' => $this->provider,
            'fallback' => $this->fallback,
            'models' => array_keys($usage),
            'providers_called' => array_values(array_unique(array_merge([], ...array_map(fn (EvalAttempt $attempt) => $attempt->providers, $this->attempts)))),
            'live' => $this->live,
            'runs' => $this->runs,
            'started_at' => $this->startedAt,
            'frozen_now' => EvalSandbox::FROZEN_NOW.' (Asia/Manila)',
            'cases' => count(array_unique(array_map(fn (EvalAttempt $attempt) => $attempt->case->id, $this->attempts))),
            'attempts' => count($this->attempts),
            'scored' => count($scored),
            'passed' => $passed,
            'failed' => count($scored) - $passed,
            'pass_rate' => $this->rate($passed, count($scored)),
            'must_never_scored' => count($mustNever),
            'must_never_failed' => count(array_filter($mustNever, fn (EvalAttempt $attempt) => ! $attempt->passed())),
            'must_never_unmeasured' => $this->unmeasuredMustNever(),
            'review' => count(array_filter($this->attempts, fn (EvalAttempt $attempt) => $attempt->status === EvalAttempt::REVIEW)),
            'provider_failed' => $providerFailed,
            'provider_failure_rate' => $this->rate($providerFailed, count($modelAttempts)),
            'skipped' => count(array_filter($this->attempts, fn (EvalAttempt $attempt) => $attempt->status === EvalAttempt::SKIPPED)),
            'errors' => count(array_filter($this->attempts, fn (EvalAttempt $attempt) => $attempt->status === EvalAttempt::ERROR)),
            'provider_calls' => count($latencies),
            'answered_by_code' => count(array_filter($this->attempts, fn (EvalAttempt $attempt) => $attempt->answeredByCode())),
            'answered_by_code_share' => $this->rate(count(array_filter($this->attempts, fn (EvalAttempt $attempt) => $attempt->answeredByCode())), count($modelAttempts)),
            'failovers' => array_sum(array_map(fn (EvalAttempt $attempt) => $attempt->failovers, $this->attempts)),
            'retries' => array_sum(array_map(fn (EvalAttempt $attempt) => $attempt->retries, $this->attempts)),
            'latency_p50_ms' => $this->percentile($latencies, 50),
            'latency_p95_ms' => $this->percentile($latencies, 95),
            'tokens' => $usage,
            'cost_usd' => $cost,
            'cost_per_model_case_usd' => $cost === null || $called === [] ? null : round($cost / count($called), 6),
            'cost_note' => 'estimate from config assistant.pricing, verify prices',
            'categories' => $this->categories(),
            'flaky' => $this->flaky(),
            'gate' => $this->gate(),
            'characters' => $this->characters(),
            'guards' => $this->guards(),
            'possible_lost_spaces' => $this->possibleLostSpaces(),
            'hedged_opt_outs' => $this->hedgedOptOuts(),
        ];
    }

    /**
     * The glued-words diagnostic (7E-2e, not a gate): how many model replies held a character PlainText exists for
     * (special spaces, soft hyphen, invisible format or control characters) in the RAW reply, and in the final one.
     *
     * @return array{replies: int, raw_with_unusual: int, final_with_unusual: int, top_raw: array<string, int>, glue_hits: int}
     */
    public function characters(): array
    {
        $described = array_values(array_filter(array_map(fn (EvalAttempt $attempt) => $attempt->characters, $this->attempts)));
        $top = [];
        $rawWith = 0;
        $finalWith = 0;

        foreach ($described as $row) {
            $raw = EvalCharacters::suspicious($row['raw']);
            $rawWith += $raw === [] ? 0 : 1;
            $finalWith += EvalCharacters::suspicious($row['final']) === [] ? 0 : 1;

            foreach ($raw as $code => $count) {
                $top[$code] = ($top[$code] ?? 0) + $count;
            }
        }

        arsort($top);

        return ['replies' => count($described), 'raw_with_unusual' => $rawWith, 'final_with_unusual' => $finalWith,
            'top_raw' => array_slice($top, 0, 5, true), 'glue_hits' => array_sum(array_map(fn (array $row) => count($row['glue']), $described))];
    }

    /**
     * Sentences ClaimGuard, PriceGuard and the other cleanups removed (7E-2e, not a gate): totals per guard, the share of
     * model replies with a removal, and which cases (id, guard, count, never text). A sudden rise is a new false positive.
     *
     * @return array{removed: array<string, int>, replaced: array<string, int>, model_replies: int, with_removal: int, share: ?float, cases: list<array{id: string, run: int, guard: string, count: int}>}
     */
    public function guards(): array
    {
        $removed = [];
        $replaced = [];
        $cases = [];
        $model = 0;
        $with = 0;

        foreach ($this->attempts as $attempt) {
            if ($attempt->providerCalled && $attempt->case->feature !== 'claimguard') {
                $model++;
                $with += ($attempt->guards['removed'] ?? []) === [] ? 0 : 1;
            }

            foreach ($attempt->guards['removed'] ?? [] as $guard => $count) {
                $removed[$guard] = ($removed[$guard] ?? 0) + $count;
                $cases[] = ['id' => $attempt->case->id, 'run' => $attempt->run, 'guard' => $guard, 'count' => $count];
            }

            foreach ($attempt->guards['replaced'] ?? [] as $guard => $count) {
                $replaced[$guard] = ($replaced[$guard] ?? 0) + $count;
            }
        }

        return ['removed' => $removed, 'replaced' => $replaced, 'model_replies' => $model, 'with_removal' => $with, 'share' => $this->rate($with, $model), 'cases' => $cases];
    }

    /** Words of model replies that look like two joined words (7E-2e, a rough guess, never a gate). @return array{count: int, candidates: list<string>} */
    public function possibleLostSpaces(): array
    {
        $found = [];

        foreach ($this->attempts as $attempt) {
            if ($attempt->providerCalled) {
                foreach (EvalChecks::possibleLostSpaces($attempt->reply) as $word) {
                    $found[] = $attempt->case->id.': '.$word;
                }
            }
        }

        $found = array_values(array_unique($found));

        return ['count' => count($found), 'candidates' => array_slice($found, 0, 30)];
    }

    /** Cases that read their whole reply, hedged clauses included ("ignore_hedged_clauses": false). @return list<string> */
    public function hedgedOptOuts(): array
    {
        return array_values(array_unique(array_map(fn (EvalAttempt $attempt) => $attempt->case->id,
            array_filter($this->attempts, fn (EvalAttempt $attempt) => ($attempt->case->expect['ignore_hedged_clauses'] ?? true) === false))));
    }

    /** @return array<string, array{scored: int, passed: int, pass_rate: ?float, review: int, provider_failed: int}> */
    public function categories(): array
    {
        $table = [];

        foreach (CaseBank::CATEGORIES as $category) {
            $in = array_filter($this->attempts, fn (EvalAttempt $attempt) => $attempt->case->category === $category);

            if ($in === []) {
                continue;
            }

            $scored = array_filter($in, fn (EvalAttempt $attempt) => $attempt->scored());
            $passed = count(array_filter($scored, fn (EvalAttempt $attempt) => $attempt->passed()));
            $table[$category] = [
                'scored' => count($scored),
                'passed' => $passed,
                'pass_rate' => $this->rate($passed, count($scored)),
                'review' => count(array_filter($in, fn (EvalAttempt $attempt) => $attempt->status === EvalAttempt::REVIEW)),
                'provider_failed' => count(array_filter($in, fn (EvalAttempt $attempt) => $attempt->status === EvalAttempt::PROVIDER_FAILED)),
            ];
        }

        return $table;
    }

    /** @return list<string> ids of cases whose scored runs disagree */
    public function flaky(): array
    {
        $byCase = [];

        foreach ($this->attempts as $attempt) {
            if ($attempt->scored()) {
                $byCase[$attempt->case->id][] = $attempt->passed();
            }
        }

        return array_keys(array_filter($byCase, fn (array $results) => count(array_unique($results)) > 1));
    }

    /**
     * The gate (7E-2c): "failed" when a must_never case failed in any run (always wins); else "inconclusive" when more
     * than INCONCLUSIVE_SHARE of the model attempts ended provider_failed or skipped (the run says too little to judge);
     * else "failed" for any other reason (a must_never case not measured, nothing scored, pass rate below the gate);
     * else "passed".
     *
     * @return array{passed: bool, status: string, reasons: list<string>}
     */
    public function gate(): array
    {
        $scored = array_filter($this->attempts, fn (EvalAttempt $attempt) => $attempt->scored());
        $passed = count(array_filter($scored, fn (EvalAttempt $attempt) => $attempt->passed()));
        $mustNeverFailed = array_unique(array_map(fn (EvalAttempt $attempt) => $attempt->case->id, array_filter($scored,
            fn (EvalAttempt $attempt) => $attempt->case->isMustNever() && ! $attempt->passed())));

        if ($mustNeverFailed !== []) {
            return ['passed' => false, 'status' => self::FAILED, 'reasons' => ['must_never failed in at least one run: '.implode(', ', $mustNeverFailed)]];
        }

        $model = array_filter($this->attempts, fn (EvalAttempt $attempt) => $attempt->case->isModelCase());
        $failedProvider = count(array_filter($model, fn (EvalAttempt $attempt) => $attempt->status === EvalAttempt::PROVIDER_FAILED));
        $skipped = count(array_filter($model, fn (EvalAttempt $attempt) => $attempt->status === EvalAttempt::SKIPPED));

        if ($model !== [] && ($failedProvider + $skipped) / count($model) > self::INCONCLUSIVE_SHARE) {
            return ['passed' => false, 'status' => self::INCONCLUSIVE, 'reasons' => [sprintf('%d of %d model attempts (%s%%) ended provider_failed (%d) or skipped (%d), more than %d%%: run it again later',
                $failedProvider + $skipped, count($model), $this->rate($failedProvider + $skipped, count($model)), $failedProvider, $skipped, (int) (self::INCONCLUSIVE_SHARE * 100))]];
        }

        $reasons = [];

        if (($unmeasured = $this->unmeasuredMustNever()) !== []) {
            $reasons[] = 'must_never not measured (provider failed or skipped by --limit): '.count($unmeasured).' case(s)';
        }

        if ($scored === []) {
            $reasons[] = 'nothing was scored';
        } elseif (($rate = $this->rate($passed, count($scored))) < self::PASS_RATE_GATE) {
            $reasons[] = "pass rate {$rate}% is below ".self::PASS_RATE_GATE.'%';
        }

        return ['passed' => $reasons === [], 'status' => $reasons === [] ? self::PASSED : self::FAILED, 'reasons' => $reasons];
    }

    /** The failed (and error) attempts with their checks and reply, for the report. @return list<array<string, mixed>> */
    public function failures(): array
    {
        return array_values(array_map(fn (EvalAttempt $attempt) => [
            'id' => $attempt->case->id,
            'category' => $attempt->case->category,
            'severity' => $attempt->case->severity,
            'run' => $attempt->run,
            'status' => $attempt->status,
            'failed_checks' => $attempt->failed,
            'reply' => $this->truncate($attempt->reply),
        ], array_filter($this->attempts, fn (EvalAttempt $attempt) => in_array($attempt->status, [EvalAttempt::FAIL, EvalAttempt::ERROR], true))));
    }

    /** @return list<array{id: string, run: int, reply: string}> */
    public function reviews(): array
    {
        return array_values(array_map(fn (EvalAttempt $attempt) => ['id' => $attempt->case->id, 'run' => $attempt->run, 'message' => $this->truncate($attempt->case->message), 'reply' => $this->truncate($attempt->reply)],
            array_filter($this->attempts, fn (EvalAttempt $attempt) => $attempt->status === EvalAttempt::REVIEW)));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->summary() + [
            'failures' => $this->failures(),
            'reviews' => $this->reviews(),
            'results' => array_map(fn (EvalAttempt $attempt) => [
                'id' => $attempt->case->id, 'category' => $attempt->case->category, 'severity' => $attempt->case->severity, 'run' => $attempt->run,
                'status' => $attempt->status, 'outcome' => $attempt->outcome, 'failed_checks' => $attempt->failed,
                'characters' => $attempt->characters, 'guards' => $attempt->guards,
            ], $this->attempts),
        ];
    }

    /** The report as markdown: the same text is printed on the console and saved. */
    public function markdown(): string
    {
        $s = $this->summary();
        $money = fn (?float $usd) => $usd === null ? 'no price in config' : '$'.number_format($usd, 4).' (about ₱'.number_format($usd * (float) config('assistant.pricing.usd_to_php'), 2).')';
        $lines = [
            '# Lumi eval report',
            '',
            '- Provider: '.$s['provider'].($s['fallback'] ? " (fallback {$s['fallback']})" : ' (no fallback)').($s['live'] ? ', live' : ', not live').'; models: '.($s['models'] === [] ? '-' : implode(', ', $s['models'])),
            "- Started: {$s['started_at']} (server time); frozen clock {$s['frozen_now']}; runs: {$s['runs']}",
            "- Cases: {$s['cases']}; attempts: {$s['attempts']}",
            '',
            '## Result',
            '',
            '- Overall pass rate: **'.($s['pass_rate'] === null ? '-' : $s['pass_rate'].'%')."** ({$s['passed']} of {$s['scored']} scored)",
            "- must_never: {$s['must_never_failed']} failed of {$s['must_never_scored']} scored".($s['must_never_unmeasured'] === [] ? '' : '; not measured: '.count($s['must_never_unmeasured'])),
            "- Not scored: review {$s['review']}, provider_failed {$s['provider_failed']} (provider failure rate {$s['provider_failure_rate']}%), skipped {$s['skipped']}; harness errors: {$s['errors']}",
            '- Flaky cases (different results across runs): '.($s['flaky'] === [] ? 'none' : implode(', ', $s['flaky'])),
            '- Quality gate: '.match ($s['gate']['status']) {
                self::PASSED => '**PASSED**',
                self::INCONCLUSIVE => '**INCONCLUSIVE**: '.implode('; ', $s['gate']['reasons']),
                default => '**FAILED**: '.implode('; ', $s['gate']['reasons']),
            },
            "- Retries after a 429: {$s['retries']} (each case is retried once after waiting the retry-after, at most ".EvalRunner::RETRY_MAX_SECONDS.' seconds)',
            '',
            '## Cost and speed',
            '',
            "- Provider calls: {$s['provider_calls']}; failovers: {$s['failovers']}; answered by code: {$s['answered_by_code']} ({$s['answered_by_code_share']}% of model cases)",
            '- Latency p50: '.($s['latency_p50_ms'] ?? '-').' ms; p95: '.($s['latency_p95_ms'] ?? '-').' ms',
        ];

        foreach ($s['tokens'] as $model => $tokens) {
            $lines[] = "- Tokens ({$model}): input ".number_format($tokens['input']).', output '.number_format($tokens['output']).', cache read '.number_format($tokens['cache_read']).', cache write '.number_format($tokens['cache_write']);
        }

        $lines[] = '- Estimated cost: '.$money($s['cost_usd']).'; per model case: '.$money($s['cost_per_model_case_usd']).' ('.$s['cost_note'].')';
        $lines[] = '';
        $lines[] = '## Reply hygiene (not a gate)';
        $lines[] = '';
        $c = $s['characters'];
        $lines[] = "- Replies with unusual characters: {$c['raw_with_unusual']} of {$c['replies']} (raw), {$c['final_with_unusual']} of {$c['replies']} (final); top code points: ".EvalCharacters::format($c['top_raw'])."; glued-word hits: {$c['glue_hits']}";
        $g = $s['guards'];
        $lines[] = '- Guard removals: '.($g['removed'] === [] ? 'none' : implode(', ', array_map(fn ($guard, $count) => "{$guard} {$count}", array_keys($g['removed']), $g['removed'])))
            .'; whole reply replaced: '.($g['replaced'] === [] ? 'none' : implode(', ', array_map(fn ($guard, $count) => "{$guard} {$count}", array_keys($g['replaced']), $g['replaced'])))
            ."; model replies with a removal: {$g['with_removal']} of {$g['model_replies']}".($g['share'] === null ? '' : " ({$g['share']}%)").' (a sudden rise means a new false positive)';
        foreach ($g['cases'] as $row) {
            $lines[] = "  - {$row['id']} (run {$row['run']}): {$row['guard']} {$row['count']}";
        }
        $lines[] = "- Possible lost spaces (a guess): {$s['possible_lost_spaces']['count']}".($s['possible_lost_spaces']['candidates'] === [] ? '' : ': '.implode('; ', $s['possible_lost_spaces']['candidates']));
        $lines[] = '- Cases reading hedged clauses too (ignore_hedged_clauses false): '.($s['hedged_opt_outs'] === [] ? 'none' : implode(', ', $s['hedged_opt_outs']));
        $lines[] = '';
        $lines[] = '## By category';
        $lines[] = '';
        $lines[] = '| Category | Passed | Scored | Pass rate | Review | Provider failed |';
        $lines[] = '|---|---|---|---|---|---|';

        foreach ($s['categories'] as $category => $row) {
            $lines[] = "| {$category} | {$row['passed']} | {$row['scored']} | ".($row['pass_rate'] === null ? '-' : $row['pass_rate'].'%')." | {$row['review']} | {$row['provider_failed']} |";
        }

        $lines[] = '';
        $lines[] = '## Failed cases';
        $lines[] = '';

        foreach ($this->failures() ?: [] as $failure) {
            $lines[] = "- **{$failure['id']}** ({$failure['category']}, {$failure['severity']}, run {$failure['run']}): ".implode('; ', $failure['failed_checks']);
            $lines[] = '  - Reply: '.($failure['reply'] === '' ? '(none)' : str_replace("\n", ' ', $failure['reply']));
        }

        if ($this->failures() === []) {
            $lines[] = 'None.';
        }

        $lines[] = '';
        $lines[] = '## Review cases (read by a person, never scored)';
        $lines[] = '';

        foreach ($this->reviews() as $review) {
            $lines[] = "- **{$review['id']}** (run {$review['run']}): ".str_replace("\n", ' ', $review['reply'] === '' ? '(no reply)' : $review['reply']);
        }

        if ($this->reviews() === []) {
            $lines[] = 'None.';
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * A side-by-side table of several runs on the same cases, and the cases where their results differ.
     *
     * @param  array<string, self>  $reports  provider => report
     */
    public static function compare(array $reports): string
    {
        $summaries = array_map(fn (self $report) => $report->summary(), $reports);
        $names = array_keys($reports);
        $row = fn (string $label, callable $value) => "| {$label} | ".implode(' | ', array_map($value, $summaries)).' |';
        $money = fn (?float $usd) => $usd === null ? 'no price' : '$'.number_format($usd, 5);

        $lines = ['# Lumi eval comparison', '', '| | '.implode(' | ', $names).' |', '|---|'.str_repeat('---|', count($names))];
        $lines[] = $row('Overall pass rate', fn ($s) => $s['pass_rate'].'%');
        $lines[] = $row('must_never failed', fn ($s) => $s['must_never_failed'].' of '.$s['must_never_scored']);
        $lines[] = $row('Quality gate', fn ($s) => strtoupper($s['gate']['status']));
        $lines[] = $row('Retries after a 429', fn ($s) => (string) $s['retries']);
        $lines[] = $row('Latency p50 / p95 (ms)', fn ($s) => ($s['latency_p50_ms'] ?? '-').' / '.($s['latency_p95_ms'] ?? '-'));
        $lines[] = $row('Cost per model case (estimate)', fn ($s) => $money($s['cost_per_model_case_usd']));
        $lines[] = $row('Provider failures', fn ($s) => $s['provider_failed'].' ('.$s['provider_failure_rate'].'%)');

        foreach (CaseBank::CATEGORIES as $category) {
            if (array_filter($summaries, fn ($s) => isset($s['categories'][$category])) !== []) {
                $lines[] = $row($category, fn ($s) => isset($s['categories'][$category]['pass_rate']) ? $s['categories'][$category]['pass_rate'].'%' : '-');
            }
        }

        $status = [];
        foreach ($reports as $name => $report) {
            foreach ($report->attempts as $attempt) {
                if ($attempt->run === 1) {
                    $status[$attempt->case->id][$name] = $attempt->status;
                }
            }
        }

        $differ = array_filter($status, fn (array $byProvider) => count(array_unique($byProvider)) > 1);
        $lines[] = '';
        $lines[] = '## Cases with different results';
        $lines[] = '';

        foreach ($differ as $id => $byProvider) {
            $lines[] = "- {$id}: ".implode(', ', array_map(fn ($name, $value) => "{$name} {$value}", array_keys($byProvider), $byProvider));
        }

        if ($differ === []) {
            $lines[] = 'None.';
        }

        return implode("\n", $lines)."\n";
    }

    /** @return list<string> must_never case ids with no scored attempt at all */
    private function unmeasuredMustNever(): array
    {
        $scored = [];
        $all = [];

        foreach ($this->attempts as $attempt) {
            if ($attempt->case->isMustNever()) {
                $all[$attempt->case->id] = true;
                $scored[$attempt->case->id] = ($scored[$attempt->case->id] ?? false) || $attempt->scored();
            }
        }

        return array_keys(array_filter($scored, fn (bool $measured) => ! $measured));
    }

    /** @param array<string, array{input: int, output: int, cache_read: int, cache_write: int}> $usage */
    private function cost(array $usage): ?float
    {
        $total = 0.0;
        $priced = false;

        foreach ($usage as $model => $tokens) {
            $price = ((array) config('assistant.pricing.models', []))[$model] ?? null;

            if (! is_array($price)) {
                continue;
            }

            $priced = true;
            $total += ($tokens['input'] * (float) ($price['input'] ?? 0) + $tokens['output'] * (float) ($price['output'] ?? 0)
                + $tokens['cache_read'] * (float) ($price['cache_read'] ?? 0) + $tokens['cache_write'] * (float) ($price['cache_write'] ?? 0)) / 1_000_000;
        }

        return $priced ? round($total, 6) : ($usage === [] ? 0.0 : null);
    }

    private function rate(int $part, int $whole): ?float
    {
        return $whole === 0 ? null : round($part / $whole * 100, 1);
    }

    /** @param list<int> $sorted */
    private function percentile(array $sorted, int $percent): ?int
    {
        if ($sorted === []) {
            return null;
        }

        return $sorted[(int) max(0, ceil($percent / 100 * count($sorted)) - 1)];
    }

    private function truncate(string $text): string
    {
        return mb_strlen($text) > self::REPLY_CHARS ? mb_substr($text, 0, self::REPLY_CHARS).'…' : $text;
    }
}
