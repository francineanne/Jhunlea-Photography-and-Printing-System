<?php

namespace App\Models\Concerns;

use App\Enums\PaymentRecordStatus;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * A printing order or a photography booking that the customer pays for at the shop. Its payment status is never
 * stored: paymentStatus() derives it from the confirmed price and the recorded payment (PaymentStatus::derive()).
 */
trait HasPayments
{
    /**
     * Every payment row, recorded and voided, newest first.
     *
     * @return MorphMany<Payment, $this>
     */
    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable')->latest('id');
    }

    /**
     * The recorded payment, if any (there is never more than one: see the unique index on payments).
     *
     * @return MorphOne<Payment, $this>
     */
    public function recordedPayment(): MorphOne
    {
        return $this->morphOne(Payment::class, 'payable')->where('status', PaymentRecordStatus::Recorded);
    }

    public function paymentStatus(): PaymentStatus
    {
        return PaymentStatus::derive($this->confirmed_price, $this->recordedPayment !== null);
    }

    /** Whether a payment line belongs on this record at all: not on a closed one that was never paid. */
    public function showsPayment(): bool
    {
        return ! $this->status->isClosed() || $this->recordedPayment !== null;
    }

    /** The amount to pay at the shop: the confirmed price, never the estimate. Null while there is none. */
    public function amountDue(): ?string
    {
        return $this->confirmed_price;
    }

    /**
     * Whether the page offers "Record payment". A display hint only: PaymentService checks the same rules again,
     * on the locked row, and says why when it refuses.
     */
    public function paymentCanBeRecorded(): bool
    {
        return $this->status->takesPayment()
            && $this->confirmed_price !== null
            && $this->paymentStatus() === PaymentStatus::Unpaid;
    }

    /** Released (Completed): the payment is final and can no longer be voided. */
    public function paymentIsFinal(): bool
    {
        return $this->status->isFinal() && ! $this->status->isClosed();
    }
}
