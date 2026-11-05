<?php

namespace App\Services\Assistant;

use App\Enums\AssistantFeature;
use App\Enums\AssistantIntent;
use App\Enums\AssistantOutcome;
use App\Models\AssistantRun;

/**
 * Writes the assistant_runs metadata rows of one provider walk (Phase 7E-3a, extracted from AssistantEngine): one row
 * per call that reached a provider and failed over, then the final row. Metadata only, never message text; since
 * 7E-3a the owner's Ask Lumi adds the TOOL NAMES it used (never a number or a name).
 */
class AssistantRunWriter
{
    /** @param array<string, mixed> $extra more columns of the final row (for example tools) */
    public function record(AssistantFeature $feature, AssistantActor $actor, ProviderCall $call, AssistantOutcome $outcome, ?AssistantIntent $intent, array $extra = []): void
    {
        $base = ['user_id' => $actor->userId, 'guest_key' => $actor->guestKey, 'feature' => $feature];

        foreach ($call->failedCalls as $failed) {
            $this->write($base + [
                'provider' => mb_substr($failed['provider']->name(), 0, 20),
                'model' => $failed['provider']->model(),
                'outcome' => $failed['outcome'],
                'latency_ms' => $failed['latency_ms'],
            ]);
        }

        $this->write($base + [
            'provider' => mb_substr($call->provider?->name() ?? (string) config('assistant.provider'), 0, 20),
            'model' => $call->provider?->model(),
            'outcome' => $outcome,
            'intent' => $intent,
            'input_tokens' => $call->response?->inputTokens,
            'output_tokens' => $call->response?->outputTokens,
            'cache_read_tokens' => $call->response?->cacheReadTokens,
            'cache_write_tokens' => $call->response?->cacheWriteTokens,
            'latency_ms' => $call->latencyMs,
        ] + ($call->fallbackFrom === null ? [] : ['fallback_from' => mb_substr($call->fallbackFrom, 0, 20)]) + $extra);
    }

    /**
     * A row that cannot be written (for example the table is not migrated yet) never breaks the answer. A database
     * without the newer columns still gets the row, without those values: first without tools (7E-3a), then without
     * the cache columns (7E-2a), then also without fallback_from (7E-1).
     *
     * @param  array<string, mixed>  $row
     */
    public function write(array $row): void
    {
        $attempts = [
            $row,
            array_diff_key($row, array_flip(['tools'])),
            array_diff_key($row, array_flip(['tools', 'cache_read_tokens', 'cache_write_tokens'])),
            array_diff_key($row, array_flip(['tools', 'cache_read_tokens', 'cache_write_tokens', 'fallback_from'])),
        ];

        $tried = [];

        foreach ($attempts as $attempt) {
            if (in_array($attempt, $tried, true)) {
                continue;
            }

            $tried[] = $attempt;

            if (rescue(fn () => AssistantRun::query()->create($attempt), null, false) !== null) {
                return;
            }
        }
    }
}
