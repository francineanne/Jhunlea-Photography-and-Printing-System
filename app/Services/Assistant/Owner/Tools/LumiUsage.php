<?php

namespace App\Services\Assistant\Owner\Tools;

use App\Models\AssistantRun;
use App\Models\User;
use App\Services\Assistant\Owner\OwnerTool;
use App\Services\Assistant\Owner\ToolResult;
use App\Support\LocalTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * How much Lumi was used, from assistant_runs metadata only (never a message): provider calls per feature, answers by
 * code, failovers, and an ESTIMATED cost from config assistant.pricing, against today's shop budget.
 */
class LumiUsage extends OwnerTool
{
    public function name(): string
    {
        return 'lumi_usage';
    }

    public function description(): string
    {
        return 'How much Lumi was used in a period: AI calls per feature, answers by code, estimated cost, and today\'s calls against the daily budget.';
    }

    public function parameters(): array
    {
        return self::rangeParameters();
    }

    protected function handle(array $params, User $owner): ToolResult
    {
        $range = $this->range($params);
        $result = new ToolResult($this->name());
        $runs = AssistantRun::query()
            ->when($range->from, fn (Builder $q, string $day) => $q->where('created_at', '>=', LocalTime::dayStartUtc($day)))
            ->when($range->to, fn (Builder $q, string $day) => $q->where('created_at', '<=', LocalTime::dayEndUtc($day)));
        $cache = Schema::hasColumn('assistant_runs', 'cache_read_tokens');

        $groups = (clone $runs)->toBase()
            ->selectRaw('feature, model, SUM(CASE WHEN latency_ms IS NOT NULL THEN 1 ELSE 0 END) as calls, SUM(CASE WHEN provider = \'code\' THEN 1 ELSE 0 END) as by_code, '
                .'COALESCE(SUM(input_tokens), 0) as input, COALESCE(SUM(output_tokens), 0) as output'
                .($cache ? ', COALESCE(SUM(cache_read_tokens), 0) as cache_read, COALESCE(SUM(cache_write_tokens), 0) as cache_write' : ', 0 as cache_read, 0 as cache_write'))
            ->groupBy('feature', 'model')->get();

        $features = [];
        $usd = 0.0;

        foreach ($groups as $group) {
            $features[$group->feature] ??= ['calls' => 0, 'by_code' => 0];
            $features[$group->feature]['calls'] += (int) $group->calls;
            $features[$group->feature]['by_code'] += (int) $group->by_code;
            $price = ((array) config('assistant.pricing.models', []))[(string) $group->model] ?? null;

            if (is_array($price)) {
                $usd += ($group->input * (float) ($price['input'] ?? 0) + $group->output * (float) ($price['output'] ?? 0)
                    + $group->cache_read * (float) ($price['cache_read'] ?? 0) + $group->cache_write * (float) ($price['cache_write'] ?? 0)) / 1_000_000;
            }
        }

        foreach ($features as $feature => $row) {
            $result->rows[] = ['feature' => $feature, 'ai_calls' => $result->count($row['calls']), 'answered_by_code' => $result->count($row['by_code'])];
        }

        $today = AssistantRun::query()->calledProvider()->where('created_at', '>=', LocalTime::dayStartUtc(LocalTime::format(now(), 'Y-m-d')))->count();
        $php = $usd * (float) config('assistant.pricing.usd_to_php');

        $result->total = count($result->rows);
        $result->data = [
            'period' => $result->label($range->label),
            'from' => $result->day($range->from),
            'to' => $result->day($range->to),
            'ai_calls' => $result->count(array_sum(array_column($features, 'calls'))),
            'failovers_to_backup' => $result->count((clone $runs)->whereNotNull('fallback_from')->count()),
            'estimated_cost' => 'about '.$result->money((int) round($php * 100)).' (an estimate, not a bill)',
            'ai_calls_today' => $result->count($today),
            'daily_budget' => $result->count((int) config('assistant.limits.global_daily_budget')),
        ];

        return $result;
    }
}
