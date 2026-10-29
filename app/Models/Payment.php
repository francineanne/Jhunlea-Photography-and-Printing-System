<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentRecordStatus;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One payment the shop received face to face, for a printing order or a photography booking. Written and voided only
 * by PaymentService; nothing is fillable, and a payment row is never deleted. The amount is the confirmed price at the
 * moment it was recorded.
 */
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    // The value of `active` while a payment is recorded; NULL once it is voided (see the unique index in the migration)
    public const ACTIVE = 1;

    /** A GCash reference as the shop types it from the customer's phone: letters, digits and hyphens. */
    public const GCASH_REFERENCE_PATTERN = '/^[A-Za-z0-9-]{6,40}$/';

    public const VOID_REASON_MAX = 500;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'method' => PaymentMethod::class,
            'status' => PaymentRecordStatus::class,
            'received_at' => 'datetime',
            'voided_at' => 'datetime',
            'active' => 'integer',
        ];
    }

    /**
     * The printing order or photography booking paid for, even after it was removed.
     *
     * @return MorphTo<Model, $this>
     */
    public function payable(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /** @return BelongsTo<User, $this> */
    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /** @param  Builder<Payment>  $query */
    public function scopeRecorded(Builder $query): void
    {
        $query->where('status', PaymentRecordStatus::Recorded);
    }

    /**
     * The recorded payment of an order or booking, as a query (used inside the locked transactions of the services).
     *
     * @return Builder<Payment>
     */
    public static function recordedFor(Model $payable): Builder
    {
        return self::query()->recorded()->where('payable_type', $payable->getMorphClass())->where('payable_id', $payable->getKey());
    }

    public function isRecorded(): bool
    {
        return $this->status === PaymentRecordStatus::Recorded;
    }

    /** "Cash" or "GCash · 1234567890123". */
    public function methodText(): string
    {
        return $this->method->label().($this->gcash_reference ? ' · '.$this->gcash_reference : '');
    }
}
