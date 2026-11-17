<?php

namespace App\Models;

use App\Enums\ColorScheme;
use App\Enums\UserRole;
use App\Notifications\VerifyEmailNotification;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

// `role`, `theme` and `is_walk_in` are deliberately not fillable: server code sets them (theme only through
// AccountService, is_walk_in only through WalkInCustomerService). A walk-in customer (Phase 8A) has no email and no
// password, so they can never sign in, reset a password or get a notification. `report_view` (Phase 8C, the owner's
// remembered income report view) is not fillable either: only ReportViewPreference writes it. `avatar_path` (Phase 10C)
// is written only by AvatarService.
#[Fillable(['name', 'email', 'phone', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'theme' => ColorScheme::class,
            'is_walk_in' => 'boolean',
            // Phase 10B: must pay before printing (set when an order was unclaimed; PrepayFlagService only)
            'prepay_required' => 'boolean',
        ];
    }

    /**
     * A customer's printing orders (Phase 6A, for the owner's customer directory).
     *
     * @return HasMany<PrintingOrder, $this>
     */
    public function printingOrders(): HasMany
    {
        return $this->hasMany(PrintingOrder::class, 'customer_id');
    }

    /** @return HasMany<PhotographyBooking, $this> */
    public function photographyBookings(): HasMany
    {
        return $this->hasMany(PhotographyBooking::class, 'customer_id');
    }

    public function isWalkIn(): bool
    {
        return (bool) $this->is_walk_in;
    }

    /** Phase 10C: the profile picture's public address, or null (none set, or a walk-in, who never has one). */
    public function avatarUrl(): ?string
    {
        if ($this->is_walk_in || ! is_string($this->avatar_path) || $this->avatar_path === '') {
            return null;
        }

        return Storage::disk('public')->url($this->avatar_path);
    }

    /** "MC" for Maria Cruz, "A" for Ana: the fallback when there is no picture. */
    public function initials(): string
    {
        $words = preg_split('/\s+/u', trim((string) $this->name), -1, PREG_SPLIT_NO_EMPTY) ?: ['?'];
        $letters = mb_substr($words[0], 0, 1).(count($words) > 1 ? mb_substr((string) end($words), 0, 1) : '');

        return mb_strtoupper($letters);
    }

    /**
     * Phase 10C: the shop's own "verify your email" message. A walk-in has no email and is never sent anything.
     */
    public function sendEmailVerificationNotification(): void
    {
        if ($this->is_walk_in || $this->email === null) {
            return;
        }

        $this->notify(new VerifyEmailNotification);
    }
}
