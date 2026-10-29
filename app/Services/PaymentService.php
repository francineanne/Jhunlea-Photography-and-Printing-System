<?php

namespace App\Services;

use App\Enums\BookingEvent;
use App\Enums\BookingStatus;
use App\Enums\OrderEvent;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRecordStatus;
use App\Enums\PrintingOrderStatus;
use App\Enums\UserRole;
use App\Exceptions\PaymentException;
use App\Models\Payment;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Models\User;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only code that records or voids a payment. The shop takes the full confirmed price in one payment, face to face
 * (cash or GCash); the system only records it and never takes money online. The amount is never typed: it is the
 * confirmed price read from the locked order or booking, so a partial or wrong amount cannot be saved.
 *
 * Everything runs in one transaction with the order or booking row locked and read again first. The payments table
 * also refuses a second recorded payment for the same order or booking, and a GCash reference that already belongs to
 * a recorded payment (unique indexes on `active`), so a double click or two people at once still save only one.
 */
class PaymentService
{
    // A transaction that loses a lock race to another one (a deadlock on MySQL, "database is locked" on SQLite) is run
    // again from the start, re-reading everything; the rules then refuse what the first one already saved
    private const ATTEMPTS = 5;

    public function __construct(
        private readonly ReferenceNumberService $references,
        private readonly StatusTransitionService $orders,
        private readonly BookingTransitionService $bookings,
    ) {}

    /**
     * @param  string|null  $expectedAmount  the amount the owner saw and confirmed; when the confirmed price changed
     *                                       since, nothing is saved (a stale page). It is a check, never the amount.
     *
     * @throws PaymentException when the rules refuse it (the message says why)
     */
    public function record(PrintingOrder|PhotographyBooking $payable, User $owner, PaymentMethod $method, ?string $gcashReference = null, ?string $expectedAmount = null): Payment
    {
        $this->ownerOnly($owner);
        $reference = $method === PaymentMethod::Gcash ? $this->gcashReference($gcashReference) : null;

        try {
            return DB::transaction(function () use ($payable, $owner, $method, $reference, $expectedAmount) {
                $current = $this->lock($payable);
                $this->checkRecordable($current, $expectedAmount);

                if ($reference !== null && Payment::query()->recorded()->where('gcash_reference', $reference)->exists()) {
                    throw PaymentException::gcashReferenceTaken();
                }

                $payment = new Payment;
                $payment->forceFill([
                    'reference_number' => $this->references->next(ReferenceNumberService::PAYMENT),
                    'payable_type' => $current->getMorphClass(),
                    'payable_id' => $current->getKey(),
                    'amount' => $current->confirmed_price,
                    'method' => $method,
                    'gcash_reference' => $reference,
                    'received_by' => $owner->getKey(),
                    'received_at' => Carbon::now('UTC'),
                    'status' => PaymentRecordStatus::Recorded,
                    'active' => Payment::ACTIVE,
                ])->save();

                // The customer reads this history: the GCash reference stays out of it
                $note = 'Payment recorded ('.$method->label().'): '.$payment->reference_number.', '.Money::format($payment->amount).'.';
                $this->history($current, $owner, 'recorded', $note);

                $payable->unsetRelation('recordedPayment')->unsetRelation('payments');

                return $payment;
            }, self::ATTEMPTS);
        } catch (UniqueConstraintViolationException) {
            // Another request saved first (a double click, or two people at once): say which rule it hit
            throw Payment::query()->recorded()->where('payable_type', $payable->getMorphClass())->where('payable_id', $payable->getKey())->exists()
                ? PaymentException::alreadyPaid()
                : PaymentException::gcashReferenceTaken();
        }
    }

    /**
     * Cancels a payment record the shop made by mistake. Allowed until the order or photos are released (Completed);
     * after that the payment is final. The reason stays in the payments table; the customer's history says only that
     * the shop cancelled the record. The order or booking can then be paid again.
     *
     * @throws PaymentException
     */
    public function void(PrintingOrder|PhotographyBooking $payable, Payment $payment, User $owner, string $reason): Payment
    {
        $this->ownerOnly($owner);
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > Payment::VOID_REASON_MAX) {
            throw PaymentException::voidReasonRequired();
        }

        return DB::transaction(function () use ($payable, $payment, $owner, $reason) {
            $current = $this->lock($payable);
            $row = Payment::query()->lockForUpdate()->findOrFail($payment->getKey());

            if ($row->payable_type !== $current->getMorphClass() || (int) $row->payable_id !== (int) $current->getKey()) {
                throw PaymentException::wrongPayable();
            }

            if (in_array($current->status, [PrintingOrderStatus::Completed, BookingStatus::Completed], true)) {
                throw PaymentException::released($this->what($current));
            }

            if (! $row->isRecorded()) {
                throw PaymentException::notRecorded();
            }

            $row->forceFill([
                'status' => PaymentRecordStatus::Voided,
                'active' => null,
                'voided_by' => $owner->getKey(),
                'voided_at' => Carbon::now('UTC'),
                'void_reason' => $reason,
            ])->save();

            $this->history($current, $owner, 'voided', null);

            $payment->setRawAttributes($row->getAttributes(), true);
            $payable->unsetRelation('recordedPayment')->unsetRelation('payments');

            return $payment;
        }, self::ATTEMPTS);
    }

    /** The order of the checks is the order of the messages the owner reads. */
    private function checkRecordable(PrintingOrder|PhotographyBooking $current, ?string $expectedAmount): void
    {
        $what = $this->what($current);

        if ($current->status->isClosed()) {
            throw PaymentException::closed($what, $current->status->label());
        }

        if ($current->confirmed_price === null) {
            throw PaymentException::noPrice();
        }

        if (! $current->status->takesPayment()) {
            throw $current instanceof PhotographyBooking ? PaymentException::bookingNotConfirmed() : PaymentException::noPrice();
        }

        if (Money::toCents($current->confirmed_price) === 0) {
            throw PaymentException::noCharge();
        }

        if (Payment::recordedFor($current)->exists()) {
            throw PaymentException::alreadyPaid();
        }

        if ($expectedAmount !== null && Money::toCents($expectedAmount) !== Money::toCents($current->confirmed_price)) {
            throw PaymentException::priceChanged(Money::format($current->confirmed_price));
        }
    }

    /** Uppercase, so "abc-123" and "ABC-123" are the same reference on every database. */
    private function gcashReference(?string $value): string
    {
        $value = strtoupper(trim((string) $value));

        if ($value === '') {
            throw PaymentException::gcashReferenceRequired();
        }

        if (! preg_match(Payment::GCASH_REFERENCE_PATTERN, $value)) {
            throw PaymentException::gcashReferenceFormat();
        }

        return $value;
    }

    /**
     * Takes the write lock on the order or booking row first (an UPDATE that changes nothing: a row lock on MySQL, the
     * write lock on SQLite, as the booking schedule lock does), then reads it again. Two payments for the same record
     * then run one after the other instead of both reading "not paid yet".
     */
    private function lock(PrintingOrder|PhotographyBooking $payable): PrintingOrder|PhotographyBooking
    {
        $payable::query()->whereKey($payable->getKey())->toBase()->update(['updated_at' => DB::raw('updated_at')]);

        return $payable::query()->lockForUpdate()->findOrFail($payable->getKey());
    }

    /** Through the status services' own history code, so the timeline reads it like any other step. */
    private function history(PrintingOrder|PhotographyBooking $current, User $owner, string $kind, ?string $note): void
    {
        if ($current instanceof PrintingOrder) {
            $this->orders->recordPaymentEvent($current, $owner, $kind === 'recorded' ? OrderEvent::PaymentRecorded : OrderEvent::PaymentVoided, $note);
        } else {
            $this->bookings->recordPaymentEvent($current, $owner, $kind === 'recorded' ? BookingEvent::PaymentRecorded : BookingEvent::PaymentVoided, $note);
        }
    }

    private function what(PrintingOrder|PhotographyBooking $payable): string
    {
        return $payable instanceof PrintingOrder ? 'order' : 'booking';
    }

    private function ownerOnly(User $actor): void
    {
        if ($actor->role !== UserRole::Owner) {
            throw new AuthorizationException('Only the shop records payments.');
        }
    }
}
