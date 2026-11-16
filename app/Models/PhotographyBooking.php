<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\RecordSource;
use App\Models\Concerns\HasPayments;
use App\Support\Money;
use Carbon\CarbonInterface;
use Database\Factories\PhotographyBookingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

// Only what a customer types is fillable. The owner of the booking, the status, the reference number, every time and
// every price are set by server code (BookingService, BookingTransitionService), never from a request. The contact
// number is set by BookingService after it checks it; the studio's internal notes only by saveInternalNote(), and they
// are hidden so they never reach a customer through toArray() or JSON.
#[Fillable(['group_size', 'notes', 'custom_theme_text', 'wants_prints'])]
#[Hidden(['internal_notes'])]
class PhotographyBooking extends Model
{
    /** @use HasFactory<PhotographyBookingFactory> */
    use HasFactory, HasPayments, SoftDeletes;

    // Online unless the counter (WalkInBookingService) says otherwise; never fillable (Phase 8A)
    protected $attributes = ['source' => 'online'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => BookingStatus::class,
            'source' => RecordSource::class,
            'snapshot' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'requested_starts_at' => 'datetime',
            'proposed_starts_at' => 'datetime',
            'hold_expires_at' => 'datetime',
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

    /*
    |--------------------------------------------------------------------------
    | THE HOLDING RULE (Phase 10A): which bookings hold time on the schedule
    |--------------------------------------------------------------------------
    | Written once as a query (scopeHoldingTime) and once for a loaded booking (holdsTime); the two must say the same.
    | A booking holds time when it is
    |   - Confirmed or Completed (its session time), or
    |   - Requested, its hold has not run out and its time is still ahead (its requested time), or
    |   - Proposed, its hold has not run out and the proposed time is still ahead (the PROPOSED time, see heldStart()).
    | A hold that ran out simply stops holding: nothing is stored or scheduled, the request stays for the studio.
    | Phase 10B adds its case ("Awaiting downpayment" until downpayment_due_at) to these two methods and nowhere else.
    */

    /** @param  Builder<PhotographyBooking>  $query */
    public function scopeHoldingTime(Builder $query, ?CarbonInterface $now = null): void
    {
        $now = Carbon::instance($now ?? Carbon::now())->utc();

        $query->where(function (Builder $holding) use ($now) {
            $holding->whereIn('status', BookingStatus::blocking())
                ->orWhere(fn (Builder $request) => $request->where('status', BookingStatus::Requested)
                    ->where('hold_expires_at', '>', $now)->where('starts_at', '>', $now))
                ->orWhere(fn (Builder $proposal) => $proposal->where('status', BookingStatus::Proposed)
                    ->where('hold_expires_at', '>', $now)->where('proposed_starts_at', '>', $now));
        });
    }

    /** The PHP twin of scopeHoldingTime(), for a booking that is already loaded. */
    public function holdsTime(?CarbonInterface $now = null): bool
    {
        $now = Carbon::instance($now ?? Carbon::now())->utc();

        return match ($this->status) {
            BookingStatus::Confirmed, BookingStatus::Completed => true,
            BookingStatus::Requested => $this->hold_expires_at !== null && $this->hold_expires_at->gt($now) && $this->starts_at->gt($now),
            BookingStatus::Proposed => $this->hold_expires_at !== null && $this->hold_expires_at->gt($now)
                && $this->proposed_starts_at !== null && $this->proposed_starts_at->gt($now),
            default => false,
        };
    }

    /** Where the time this booking holds (or asks for) starts: the proposed time while a proposal is open. */
    public function heldStart(): Carbon
    {
        return $this->status === BookingStatus::Proposed && $this->proposed_starts_at !== null
            ? $this->proposed_starts_at->copy()
            : $this->starts_at->copy();
    }

    public function heldEnd(): Carbon
    {
        return $this->status === BookingStatus::Proposed && $this->proposed_starts_at !== null
            ? $this->proposed_starts_at->copy()->addMinutes($this->sessionMinutes())
            : $this->ends_at->copy();
    }

    /** The session length in minutes as saved on the row (ends_at - starts_at), whatever the package says today. */
    public function sessionMinutes(): int
    {
        return (int) round(($this->ends_at->getTimestamp() - $this->starts_at->getTimestamp()) / 60);
    }

    /*
    | EXPIRED (Phase 10A): a Requested or Proposed booking whose time (the proposed time for a proposal) has passed
    | without being confirmed. Derived, never stored. It holds no time, does not count toward the open-request limit,
    | and can only be rejected (studio) or cancelled. scopeExpired / scopeNotExpired and isExpired() say the same.
    */

    public const EXPIRED_LABEL = 'Expired';

    public const EXPIRED_NOTE = 'This request was not confirmed before its time.';

    /** @param  Builder<PhotographyBooking>  $query */
    public function scopeExpired(Builder $query, ?CarbonInterface $now = null): void
    {
        $now = Carbon::instance($now ?? Carbon::now())->utc();

        $query->where(function (Builder $expired) use ($now) {
            $expired->where(fn (Builder $request) => $request->where('status', BookingStatus::Requested)->where('starts_at', '<=', $now))
                ->orWhere(fn (Builder $proposal) => $proposal->where('status', BookingStatus::Proposed)->where('proposed_starts_at', '<=', $now));
        });
    }

    /**
     * Requests still waiting for an answer in time: Requested or Proposed and not expired. The open-request limit
     * counts these.
     *
     * @param  Builder<PhotographyBooking>  $query
     */
    public function scopeWaiting(Builder $query, ?CarbonInterface $now = null): void
    {
        $now = Carbon::instance($now ?? Carbon::now())->utc();

        $query->where(function (Builder $waiting) use ($now) {
            $waiting->where(fn (Builder $request) => $request->where('status', BookingStatus::Requested)->where('starts_at', '>', $now))
                ->orWhere(fn (Builder $proposal) => $proposal->where('status', BookingStatus::Proposed)
                    ->where(fn (Builder $time) => $time->whereNull('proposed_starts_at')->orWhere('proposed_starts_at', '>', $now)));
        });
    }

    public function isExpired(?CarbonInterface $now = null): bool
    {
        $now = Carbon::instance($now ?? Carbon::now())->utc();

        return match ($this->status) {
            BookingStatus::Requested => $this->starts_at->lte($now),
            BookingStatus::Proposed => $this->proposed_starts_at !== null && $this->proposed_starts_at->lte($now),
            default => false,
        };
    }

    /** The status as people read it: "Expired" for an expired request, else the status label. */
    public function statusLabel(): string
    {
        return $this->isExpired() ? self::EXPIRED_LABEL : $this->status->label();
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

    /**
     * The price that applies if the customer accepts a proposed time, as acceptProposal() sets it: the confirmed price
     * when the studio named one, else the estimate, else nothing yet.
     *
     * @return array{label: string, text: string, confirmed: bool}
     */
    public function priceIfAccepted(): array
    {
        return match (true) {
            $this->confirmed_price !== null => ['label' => 'Confirmed price', 'text' => Money::format($this->confirmed_price), 'confirmed' => true],
            $this->estimated_price !== null => ['label' => 'Estimated price', 'text' => Money::format($this->estimated_price), 'confirmed' => false],
            default => ['label' => 'Price', 'text' => 'Price confirmed by the studio', 'confirmed' => false],
        };
    }

    public function confirmedText(): ?string
    {
        return $this->confirmed_price === null ? null : Money::format($this->confirmed_price);
    }

    public function isWalkIn(): bool
    {
        return $this->source === RecordSource::WalkIn;
    }
}
