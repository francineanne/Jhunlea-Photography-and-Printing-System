<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\BookingEvent;
use App\Enums\BookingStatus;
use App\Enums\OrderEvent;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRecordStatus;
use App\Enums\PrintingOrderStatus;
use App\Enums\UserRole;
use App\Exceptions\PaymentException;
use App\Models\Payment;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Money;
use App\Support\PaymentRules;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The only code that records or voids a payment. The shop takes payments face to face (cash, or GCash it checks in
 * its own app); the system only records them and never takes money online. The amount is never typed: it is worked out
 * from the locked order or booking (PaymentRules::options()):
 *  - full: the confirmed price, when nothing is recorded yet (a printing order is always paid this way);
 *  - downpayment: the owner's percent of the price, only while a booking is "Awaiting downpayment" (Phase 10B);
 *  - balance: the price minus the active downpayment, once a downpayment is recorded.
 * Recording the downpayment or the full price of an "Awaiting downpayment" booking confirms it in the same transaction.
 *
 * Everything runs in one transaction with the order or booking row locked and read again first. The payments table
 * also refuses a second active payment of the same kind for one order or booking, and a GCash reference that already
 * belongs to an active payment (unique indexes on `active`), so a double click or two people at once still save only
 * one. LOCK ORDER (the same as everywhere, docs/MYSQL_TESTING.md): the order or booking row, then the PAY counter,
 * then the audit chain head. Never the schedule lock: a payment frees or takes no time.
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
        private readonly PaymentRules $rules,
    ) {}

    /**
     * @param  string|null  $expectedAmount  the amount the owner saw and confirmed; when it no longer matches the amount
     *                                       of this kind (the price changed since), nothing is saved (a stale page). It
     *                                       is a check, never the amount.
     * @param  PaymentKind|null  $kind  what the owner chose where more than one is allowed (a downpayment or the full
     *                                  price); null takes the only one allowed, or the full price
     *
     * @throws PaymentException when the rules refuse it (the message says why)
     */
    public function record(PrintingOrder|PhotographyBooking $payable, User $owner, PaymentMethod $method, ?string $gcashReference = null, ?string $expectedAmount = null, ?PaymentKind $kind = null): Payment
    {
        $this->ownerOnly($owner);
        $reference = $method === PaymentMethod::Gcash ? $this->gcashReference($gcashReference) : null;

        try {
            return DB::transaction(function () use ($payable, $owner, $method, $reference, $expectedAmount, $kind) {
                $current = $this->lock($payable);
                $active = Payment::recordedFor($current)->orderBy('id')->get();
                [$kind, $cents] = $this->checkRecordable($current, $active, $kind, $expectedAmount);

                if ($reference !== null && Payment::query()->recorded()->where('gcash_reference', $reference)->exists()) {
                    throw PaymentException::gcashReferenceTaken();
                }

                $payment = new Payment;
                $payment->forceFill([
                    'reference_number' => $this->references->next(ReferenceNumberService::PAYMENT),
                    'payable_type' => $current->getMorphClass(),
                    'payable_id' => $current->getKey(),
                    'amount' => Money::fromCents($cents),
                    'kind' => $kind,
                    'method' => $method,
                    'gcash_reference' => $reference,
                    'received_by' => $owner->getKey(),
                    'received_at' => Carbon::now('UTC'),
                    'status' => PaymentRecordStatus::Recorded,
                    'active' => Payment::ACTIVE,
                ])->save();

                // The customer reads this history: the GCash reference stays out of it
                $words = $kind === PaymentKind::Full ? 'Payment' : $kind->label();
                $balance = Money::toCents($current->confirmed_price) - (int) $active->sum(fn (Payment $row) => Money::toCents($row->amount)) - $cents;
                $note = $words.' recorded ('.$method->label().'): '.$payment->reference_number.', '.Money::format($payment->amount).'.'
                    .($balance > 0 ? ' Balance due: '.Money::cents($balance).'.' : '');
                $this->history($current, $owner, 'recorded', $note, ['payment' => $payment->reference_number, 'amount' => $payment->amount, 'kind' => $kind->value]);
                // Audit trail (8E), in this transaction: the PAY number, method and kind, never the GCash reference
                app(AuditLogger::class)->record(AuditAction::PaymentRecorded, $payment, ['amount_cents' => $cents, 'method' => $method->value, 'kind' => $kind->value, 'for' => $current->reference_number], actor: $owner);

                // The downpayment (or the full price) holds the time for good: the booking is confirmed now
                if ($current instanceof PhotographyBooking && $current->status === BookingStatus::AwaitingDownpayment) {
                    $this->bookings->confirmByPayment($current, $owner, $payment);
                    $payable->setRawAttributes($current->getAttributes(), true);
                }

                $payable->unsetRelation('recordedPayment')->unsetRelation('activePayments')->unsetRelation('payments');

                return $payment;
            }, self::ATTEMPTS);
        } catch (UniqueConstraintViolationException) {
            // Another request saved first (a double click, or two people at once): say which rule it hit
            throw Payment::recordedFor($payable)->exists()
                ? PaymentException::alreadyPaid()
                : PaymentException::gcashReferenceTaken();
        }
    }

    /**
     * Cancels a payment record the shop made by mistake, or (with $refund, Phase 10B) as a refund when the shop cancels
     * the order or booking. Allowed until the order or photos are released (Completed); after that the payment is final.
     * The reason stays in the payments table; the customer's history says only that the shop cancelled the record.
     *
     * A downpayment cannot be voided while its balance is recorded (void the balance first). Voiding the downpayment of
     * a Confirmed booking sends it back to "Awaiting downpayment" with a new deadline (PaymentRules::dueAfterVoid()).
     *
     * @throws PaymentException
     */
    public function void(PrintingOrder|PhotographyBooking $payable, Payment $payment, User $owner, string $reason, bool $refund = false): Payment
    {
        $this->ownerOnly($owner);
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > Payment::VOID_REASON_MAX) {
            throw PaymentException::voidReasonRequired();
        }

        return DB::transaction(function () use ($payable, $payment, $owner, $reason, $refund) {
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

            if ($row->kind === PaymentKind::Downpayment && Payment::recordedFor($current)->where('kind', PaymentKind::Balance)->exists()) {
                throw PaymentException::voidBalanceFirst();
            }

            $row->forceFill([
                'status' => PaymentRecordStatus::Voided,
                'active' => null,
                'voided_by' => $owner->getKey(),
                'voided_at' => Carbon::now('UTC'),
                'void_reason' => $reason,
            ])->save();

            $this->history($current, $owner, 'voided', null, ['payment' => $row->reference_number]);
            app(AuditLogger::class)->record($refund ? AuditAction::PaymentRefunded : AuditAction::PaymentVoided, $row,
                ['amount_cents' => Money::toCents($row->amount), 'method' => $row->method->value, 'kind' => $row->kind?->value, 'for' => $current->reference_number, 'reason' => $reason], actor: $owner);

            // Without its downpayment a Confirmed photoshoot is no longer held for good: it waits for one again
            if ($current instanceof PhotographyBooking && $current->status === BookingStatus::Confirmed && $row->kind === PaymentKind::Downpayment) {
                $this->bookings->backToDownpayment($current, $owner, $this->rules->dueAfterVoid($current->starts_at));
                $payable->setRawAttributes($current->getAttributes(), true);
            }

            $payment->setRawAttributes($row->getAttributes(), true);
            $payable->unsetRelation('recordedPayment')->unsetRelation('activePayments')->unsetRelation('payments');

            return $payment;
        }, self::ATTEMPTS);
    }

    /**
     * Voids every active payment of an order or booking as a refund, the balance first (Phase 10B: the shop cancelled it
     * and chose "Refund"). Runs inside the caller's locked transaction.
     */
    public function refundAll(PrintingOrder|PhotographyBooking $current, User $owner, string $reason): void
    {
        foreach (Payment::recordedFor($current)->orderByDesc('id')->get() as $payment) {
            $this->void($current, $payment, $owner, $reason, refund: true);
        }
    }

    /**
     * The order of the checks is the order of the messages the owner reads.
     *
     * @param  Collection<int, Payment>  $active
     * @return array{0: PaymentKind, 1: int} the kind and its amount in centavos
     */
    private function checkRecordable(PrintingOrder|PhotographyBooking $current, Collection $active, ?PaymentKind $kind, ?string $expectedAmount): array
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

        $options = $this->rules->options($current, $active);

        if ($options === []) {
            throw PaymentException::alreadyPaid();
        }

        $kind ??= count($options) === 1 ? PaymentKind::from((string) array_key_first($options)) : PaymentKind::Full;

        if (! array_key_exists($kind->value, $options)) {
            $recorded = $active->map(fn (Payment $payment) => $payment->kind)->all();

            throw match (true) {
                $kind === PaymentKind::Balance => PaymentException::balanceNeedsDownpayment(),
                in_array(PaymentKind::Downpayment, $recorded, true) => PaymentException::recordBalanceInstead(Money::cents($options[PaymentKind::Balance->value] ?? 0)),
                $kind === PaymentKind::Downpayment && $recorded === [] => PaymentException::noDownpaymentDue(),
                default => PaymentException::alreadyPaid(),
            };
        }

        $cents = $options[$kind->value];

        if ($expectedAmount !== null && Money::toCents($expectedAmount) !== $cents) {
            throw PaymentException::priceChanged(Money::cents($cents));
        }

        return [$kind, $cents];
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

    /**
     * Through the status services' own history code, so the timeline reads it like any other step.
     *
     * @param  array{payment?: string, amount?: string, kind?: string}  $context  for the customer's notification: the PAY reference, amount and kind, never the GCash reference or a void reason
     */
    private function history(PrintingOrder|PhotographyBooking $current, User $owner, string $kind, ?string $note, array $context = []): void
    {
        if ($current instanceof PrintingOrder) {
            $this->orders->recordPaymentEvent($current, $owner, $kind === 'recorded' ? OrderEvent::PaymentRecorded : OrderEvent::PaymentVoided, $note, $context);
        } else {
            $this->bookings->recordPaymentEvent($current, $owner, $kind === 'recorded' ? BookingEvent::PaymentRecorded : BookingEvent::PaymentVoided, $note, $context);
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
