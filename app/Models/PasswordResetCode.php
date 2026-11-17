<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 10C: the one active password reset code of a user, stored ONLY as a hash. Written, checked and deleted by
 * PasswordResetCodeService; nothing is fillable and the hash is never shown.
 */
class PasswordResetCode extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['code_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['attempts' => 'integer', 'expires_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
