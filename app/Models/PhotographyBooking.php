<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Support\Money;
use Database\Factories\PhotographyBookingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

// Only what a customer types is fillable. The owner of the booking, the status, the reference number, every time and
// every price are set by server code (BookingService, BookingTransitionService), never from a request. The contact
// number is set by BookingService after it checks it; the studio's internal notes only by saveInternalNote(), and they
// are hidden so they never reach a customer through toArray() or JSON.
#[Fillable(['group_size', 'notes', 'custom_theme_text', 'wants_prints'])]
#[Hidden(['internal_notes'])]
class PhotographyBooking extends Model
{
    /** @use HasFactory<PhotographyBookingFactory> */
    use HasFactory, SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => BookingStatus::class,
            'snapshot' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'requested_starts_at' => 'datetime',
            'proposed_starts_at' => 'datetime',
            'group_size' => 'integer',
            'wants_prints' => 'boolean',
            'estimated_price' => 'decimal:2',
            'confirmed_price' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /**
     * A booking keeps its package even after the owner removes it from the catalog.
     *
     * @return BelongsTo<PhotographyPackage, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(PhotographyPackage::class, 'photography_package_id')->withTrashed();
    }

    /** @return BelongsTo<Theme, $this> */
    public function theme(): BelongsTo
    {
        return $this->belongsTo(Theme::class)->withTrashed();
    }

    /** @return HasMany<BookingReferenceImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(BookingReferenceImage::class);
    }

    /** @return HasMany<BookingStatusHistory, $this> */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(BookingStatusHistory::class)->orderBy('id');
    }

    /**
     * Printing orders the customer started from this shoot.
     *
     * @return HasMany<PrintingOrder, $this>
     */
    public function printingOrders(): HasMany
    {
        return $this->hasMany(PrintingOrder::class);
    }

    /** The session length the booking was made for, from the snapshot (the package may have changed since). */
    public function durationMinutes(): int
    {
        return (int) ($this->snapshot['package']['duration_minutes'] ?? $this->package?->duration_minutes ?? 0);
    }

    /** The estimate as customers read it. No estimate means the package is priced on request. */
    public function estimateText(): string
    {
        return $this->estimated_price === null ? 'Price on request' : Money::format($this->estimated_price);
    }

    public function confirmedText(): ?string
    {
        return $this->confirmed_price === null ? null : Money::format($this->confirmed_price);
    }
}
