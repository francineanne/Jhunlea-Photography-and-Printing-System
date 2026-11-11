<?php

namespace App\Models;

use App\Enums\AuditAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One row of the audit trail (Phase 8E). Append-only: AuditLogger is the only writer and only ever inserts; this model
 * refuses every update and delete (an exception), and there is no route that edits or deletes. Nothing is fillable.
 */
class AuditLog extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'action' => AuditAction::class,
            'meta' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit log rows cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Audit log rows cannot be deleted.'));
    }

    /** Mass updates and deletes through Eloquent are refused too (they skip the model events above). */
    public function newEloquentBuilder($query): AuditLogBuilder
    {
        return new AuditLogBuilder($query);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
