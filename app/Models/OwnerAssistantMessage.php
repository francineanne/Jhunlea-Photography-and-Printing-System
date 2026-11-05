<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One turn of the owner's Ask Lumi conversation (Phase 7E-3a): role "owner" or "lumi", the text, and for Lumi's turns
 * the tool names, cards and links in meta. Nothing is fillable; OwnerAskHistory sets every column. Never tool data.
 */
class OwnerAssistantMessage extends Model
{
    public const UPDATED_AT = null;

    public const OWNER = 'owner';

    public const LUMI = 'lumi';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
