<?php

namespace App\Models;

use App\Enums\AssistantFeature;
use App\Enums\AssistantIntent;
use App\Enums\AssistantOutcome;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The metadata of one assistant call. Written only by AssistantEngine, never from a request, and never holds message
 * text. A row with latency_ms set is a call that reached the provider: only those count toward the limits.
 */
#[Fillable(['user_id', 'guest_key', 'feature', 'provider', 'model', 'outcome', 'intent', 'input_tokens', 'output_tokens', 'latency_ms'])]
class AssistantRun extends Model
{
    public const UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'feature' => AssistantFeature::class,
            'outcome' => AssistantOutcome::class,
            'intent' => AssistantIntent::class,
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'latency_ms' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param Builder<AssistantRun> $query */
    public function scopeCalledProvider(Builder $query): void
    {
        $query->whereNotNull('latency_ms');
    }
}
