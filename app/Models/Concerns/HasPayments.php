<?php

namespace App\Models\Concerns;

use App\Enums\PaymentKind;
use App\Enums\PaymentRecordStatus;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Support\Money;
use App\Support\PaymentRules;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Collection;

/**
 * A printing order or a photography booking that the customer pays for at the shop. Its payment status is never
 * stored: paymentStatus() derives it from the confirmed price and what the RECORDED payments add up to
 * (PaymentStatus::derive()). Since Phase 10B a photoshoot may have two recorded payments: a downpayment and its balance.
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
     * The recorded (active) payments, oldest first: one for an order, one or two (downpayment, balance) for a booking.
     *
     * @return MorphMany<Payment, $this>
     */
    public function activePayments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable')->where('status', PaymentRecordStatus::Recorded)->orderBy('id');
    }

    /**
     * The newest recorded payment, if any. For an order it is THE payment; for a booking with a downpayment and a
     * balance it is the balance (activePayments() lists both).
     *
     * @return MorphOne<Payment, $this>
     */
    public function recordedPayment(): MorphOne
    {
        return $this->morphOne(Payment::class, 'payable')->where('status', PaymentRecordStatus::Recorded)->orderByDesc('id');
    }

    /** @return Collection<int, Payment> */
    public function recordedPayments(): Collection
    {
        if ($this->relationLoaded('activePayments')) {
            return $this->activePayments;
        }

        // A list that loaded only recordedPayment: an order (or a booking paid in full) has at most that one payment
        if ($this->relationLoaded('recordedPayment') && ($this->recordedPayment === null || $this->recordedPayment->kind === PaymentKind::Full)) {
            return collect(array_filter([$this->recordedPayment]));
        }

        return $this->activePayments;
    }

    /** What the recorded payments add up to, in centavos. */
    public function paidCents(): int
    {
        return (int) $this->recordedPayments()->sum(fn (Payment $payment) => Money::toCents($payment->amount));
    }

    public function paymentStatus(): PaymentStatus
    {
        return PaymentStatus::derive($this->confirmed_price, $this->paidCents());
    }

    /** Whether a payment line belongs on this record at all: not on a closed one that was never paid. */
    public function showsPayment(): bool
    {
        return ! $this->status->isClosed() || $this->recordedPayments()->isNotEmpty();
    }

    /**
     * What is still to pay at the shop: the confirmed price minus what is recorded (never the estimate). Null while
     * there is no confirmed price.
     */
    public function amountDue(): ?string
    {
        return $this->confirmed_price === null ? null : Money::fromCents(max(0, Money::toCents($this->confirmed_price) - $this->paidCents()));
    }

    /**
     * What may be recorded now, kind value => amount in centavos (PaymentRules::options()). PaymentService checks the
     * same rules again on the locked row.
     *
     * @return array<string, int>
     */
    public function paymentOptions(): array
    {
        return app(PaymentRules::class)->options($this, $this->recordedPayments());
    }

    /**
     * Whether the page offers "Record payment". A display hint only: PaymentService checks the same rules again,
     * on the locked row, and says why when it refuses.
     */
    public function paymentCanBeRecorded(): bool
    {
        return $this->paymentOptions() !== [];
    }

    /** Released (Completed): the payment is final and can no longer be voided. */
    public function paymentIsFinal(): bool
    {
        return $this->status->isFinal() && ! $this->status->isClosed();
    }
}
