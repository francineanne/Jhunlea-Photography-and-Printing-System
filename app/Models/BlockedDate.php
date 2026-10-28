<?php

namespace App\Models;

use Database\Factories\BlockedDateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// created_by is not fillable: it is the signed-in owner, set by server code
#[Fillable(['date', 'reason'])]
class BlockedDate extends Model
{
    /** @use HasFactory<BlockedDateFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        // A calendar day in shop local time, never a moment: kept as the plain Y-m-d string
        return ['date' => 'date:Y-m-d'];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
