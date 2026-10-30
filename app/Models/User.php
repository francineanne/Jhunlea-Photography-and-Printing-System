<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\ColorScheme;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

// `role` and `theme` are deliberately not fillable: server code sets them (theme only through AccountService).
#[Fillable(['name', 'email', 'phone', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
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
}
