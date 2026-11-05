<?php

namespace App\Console\Commands;

use App\Enums\AssistantOutcome;
use App\Models\AssistantRun;
use App\Support\LocalTime;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * How the assistant was used (Phase 7E-2a): per provider and feature, the calls, their outcomes, the failovers to the
 * backup, the tokens (input, output, cache read and write), the share of replies answered by code, and an ESTIMATED
 * cost from the price table in config assistant.pricing. Read only: it reads assistant_runs, which holds metadata only,
 * so no prompt or message text can ever be printed. Days are Manila days, today included.
 */
#[Signature('assistant:usage {--days=7 : How many Manila days to report, today included (1 to 366)}')]
#[Description('Report the AI assistant usage, tokens and an estimated cost from assistant_runs')]
class AssistantUsage extends Command
{
    /** The outcomes that put a reply in front of a customer or the owner. */
    private const REPLIES = [AssistantOutcome::Answered, AssistantOutcome::AnsweredByCode, AssistantOutcome::Handoff, AssistantOutcome::Refused];

    public function handle(): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 366]]);

        if ($days === false) {
            $this->error('--days must be a whole number from 1 to 366.');

            return self::FAILURE;
        }

        $zone = config('app.display_timezone');
        $first = Carbon::now($zone)->subDays($days - 1)->toDateString();
        $from = LocalTime::dayStartUtc($first);
        $has = fn (string $column) => Schema::hasColumn('assistant_runs', $column);
        $sum = fn (string $column) => $has($column) ? "COALESCE(SUM({$column}), 0)" : '0';

        $runs = AssistantRun::query()->where('created_at', '>=', $from);

        $this->info("Assistant usage from {$first} to ".Carbon::now($zone)->toDateString()." (Manila days: {$days})");

        $groups = (clone $runs)
            ->selectRaw('provider, feature, model, COUNT(*) AS runs, SUM(CASE WHEN latency_ms IS NOT NULL THEN 1 ELSE 0 END) AS calls, '
                .($has('fallback_from') ? 'SUM(CASE WHEN fallback_from IS NOT NULL THEN 1 ELSE 0 END)' : '0').' AS failovers, '
                .$sum('input_tokens').' AS input_tokens, '.$sum('output_tokens').' AS output_tokens, '
                .$sum('cache_read_tokens').' AS cache_read_tokens, '.$sum('cache_write_tokens').' AS cache_write_tokens')
            ->groupBy('provider', 'feature', 'model')
            ->orderBy('provider')->orderBy('feature')->orderBy('model')
            ->toBase()->get();

        if ($groups->isEmpty()) {
            $this->line('No assistant runs in this period.');

            return self::SUCCESS;
        }

        $outcomes = (clone $runs)->selectRaw('provider, feature, model, outcome, COUNT(*) AS total')
            ->groupBy('provider', 'feature', 'model', 'outcome')->toBase()->get()
            ->groupBy(fn ($row) => $row->provider.'|'.$row->feature.'|'.$row->model);

        $rate = (float) config('assistant.pricing.usd_to_php');
        $totalUsd = 0.0;
        $unpriced = false;
        $rows = [];

        foreach ($groups as $group) {
            $usd = $this->cost($group);
            $unpriced = $unpriced || ($usd === null && $group->calls > 0 && $group->provider !== 'code');
            $totalUsd += $usd ?? 0.0;

            $rows[] = [
                $group->provider,
                $group->feature,
                $group->model ?? '-',
                (int) $group->calls,
                ($outcomes[$group->provider.'|'.$group->feature.'|'.$group->model] ?? collect())->sortBy('outcome')->map(fn ($row) => "{$row->outcome} {$row->total}")->implode(', '),
                (int) $group->failovers,
                number_format((int) $group->input_tokens),
                number_format((int) $group->output_tokens),
                number_format((int) $group->cache_read_tokens),
                number_format((int) $group->cache_write_tokens),
                $usd === null ? ($group->provider === 'code' ? '0' : 'no price') : '$'.number_format($usd, 4).' / ₱'.number_format($usd * $rate, 2),
            ];
        }

        $this->table(['Provider', 'Feature', 'Model', 'Calls', 'Outcomes', 'Failovers', 'Input', 'Output', 'Cache read', 'Cache write', 'Cost (estimate)'], $rows);

        $replies = (clone $runs)->whereIn('outcome', array_map(fn (AssistantOutcome $outcome) => $outcome->value, self::REPLIES))->count();
        $byCode = (clone $runs)->where('outcome', AssistantOutcome::AnsweredByCode->value)->count();
        $calls = (clone $runs)->whereNotNull('latency_ms')->count();

        $this->line("Provider calls: {$calls}. Failovers to the backup: ".(int) $groups->sum('failovers').'.');
        $this->line("Answered by code: {$byCode} of {$replies} replies (".($replies === 0 ? '0' : number_format($byCode / $replies * 100, 1)).'%), with no provider call.');
        $this->line('Estimated cost: $'.number_format($totalUsd, 4).' (about ₱'.number_format($totalUsd * $rate, 2).' at ₱'.number_format($rate, 2).' per USD).');
        $this->warn('This is an ESTIMATE from config assistant.pricing: verify the prices on the providers\' pricing pages.'.($unpriced ? ' Models marked "no price" are not counted.' : ''));

        return self::SUCCESS;
    }

    /** USD for one group, or null when its model has no row in the price table. */
    private function cost(object $group): ?float
    {
        // Not a dotted config path: a model name may hold a dot (gemini-3.5-flash-lite)
        $price = ((array) config('assistant.pricing.models', []))[(string) $group->model] ?? null;

        if (! is_array($price)) {
            return null;
        }

        return ((int) $group->input_tokens * (float) ($price['input'] ?? 0)
            + (int) $group->output_tokens * (float) ($price['output'] ?? 0)
            + (int) $group->cache_read_tokens * (float) ($price['cache_read'] ?? 0)
            + (int) $group->cache_write_tokens * (float) ($price['cache_write'] ?? 0)) / 1_000_000;
    }
}
