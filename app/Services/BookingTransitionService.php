<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\BookingEvent;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Exceptions\BookingTransitionException;
use App\Models\BookingSetting;
use App\Models\BookingStatusHistory;
use App\Models\Payment;
use App\Models\PhotographyBooking;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\LocalTime;
use App\Support\Money;
use App\Support\PaymentRules;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only code that changes the status of a photography booking, and its proposal and price. Which moves are
 * allowed, and for which role, is written once in BookingStatus::transitions(). Every change re-reads the booking
 * on a locked row inside a transaction and saves its history row with it, so a page that is out of date or a double
 * click can never skip the rules or write twice.
 *
 * Moves that put a booking on the schedule or give it a hold (confirm, propose, accept a proposal, ask for another
 * time; and outside this class a new request in BookingService::place() and a walk-in booking) first take the
 * SCHEDULE LOCK: they bump the single booking_settings row, which every such move must also update, so two of them run
 * one after the other even for two different bookings. The conflict check runs after the lock is held, so two
 * overlapping moves can never both win. (An UPDATE locks the row on MySQL and takes the write lock on SQLite.)
 *
 * LOCK ORDER (Phase 10A; the same everywhere, so no two of these can deadlock):
 *   1. the schedule lock (booking_settings row 1), when the move needs it - always FIRST;
 *   2. the customer's users row (BookingService::place() only, for the open-request limit);
 *   3. the booking row (locked());
 *   4. the reference counter row (ReferenceNumberService, a new booking or payment), then the inserts.
 * Nothing that already holds a booking, customer or counter row ever asks for the schedule lock afterwards.
 * PaymentService takes only 3 and 4 (booking row, then the PAY counter), never the schedule lock. The audit chain head
 * is always the LAST lock (AuditLogger, at the end of each action).
 *
 * DOWNPAYMENT (Phase 10B): confirming (or accepting a proposal) needs a downpayment when PaymentRules says so; the
 * booking then waits as "Awaiting downpayment" until downpayment_due_at and holds its time until then
 * (PhotographyBooking::scopeHoldingTime). Recording the downpayment or the full price confirms it (PaymentService calls
 * confirmByPayment() in its own transaction); the owner may also waive it or extend the deadline; expire() cancels it
 * when the deadline passed (DownpaymentExpiryService runs it, the actor is the system).
 */
class BookingTransitionService
{
    public const INTERNAL_NOTE_MAX = 2000;

    // How long the notes and reasons typed with a change may be; the forms read them from here
    public const NOTE_MAX = 500;

    public const REASON_MAX = 1000;

    public function __construct(
        private readonly ScheduleConflictService $conflicts,
        private readonly AvailabilityService $availability,
        private readonly NotificationDispatcher $notifications,
        private readonly PaymentRules $rules,
    ) {}

    /** Writes the first history row of a new booking. Called by BookingService. */
    public function recordRequest(PhotographyBooking $booking, User $customer): void
    {
        $this->writeHistory($booking, null, BookingStatus::Requested, $customer, null);
    }

    /**
     * The first history row of a booking the owner made at the counter (Phase 8A): it starts Confirmed. Called by
     * WalkInBookingService only, after its own schedule checks under the schedule lock.
     */
    public function recordCounterBooking(PhotographyBooking $booking, User $owner, string $note): void
    {
        $this->writeHistory($booking, null, $booking->status, $owner, $note);
        app(AuditLogger::class)->record(AuditAction::BookingWalkInCreated, $booking, ['price_cents' => AuditLogger::cents($booking->confirmed_price), 'customer' => $booking->customer?->name], actor: $owner);
    }

    /**
     * Phase 10C: the owner waived the downpayment at the counter. Written as the same history event as an online waiver
     * (DownpaymentWaived), so a later void of a full payment knows not to ask for a downpayment again. Called by
     * WalkInBookingService in its own transaction, right after the first history row.
     */
    public function recordCounterWaiver(PhotographyBooking $booking, User $owner, string $reason): void
    {
        $this->writeHistory($booking, $booking->status, $booking->status, $owner, 'Downpayment waived: '.$reason, BookingEvent::DownpaymentWaived);
        app(AuditLogger::class)->record(AuditAction::BookingDownpaymentWaived, $booking, ['reason' => $reason], actor: $owner);
    }

    /**
     * The schedule lock of confirm(), for BookingService::place() and WalkInBookingService (held until the surrounding
     * transaction ends). It must be the FIRST lock taken in that transaction (see the lock order above).
     */
    public function lockSchedule(): void
    {
        $this->takeScheduleLock();
    }

    /**
     * The studio confirms the time the customer asked for. The price defaults to the estimate and may be changed;
     * a package priced on request has no estimate, so the price is required.
     *
     * A clash with a booking that holds the time (PhotographyBooking::scopeHoldingTime: a confirmed session, or another
     * request or proposal whose hold has not run out) always refuses; the request's own hold never stands in its way,
     * and a request whose hold ran out is confirmed like any other when the time is still free. A time that now breaks one of the schedule rules (for example
     * the studio blocked the date after the request came in) is confirmed only when the studio says so with
     * $outsideSchedule, and the history then says which rules were set aside.
     */
    public function confirm(PhotographyBooking $booking, User $owner, ?string $price = null, ?string $note = null, bool $outsideSchedule = false): PhotographyBooking
    {
        return $this->locked($booking, $owner, function (PhotographyBooking $current) use ($owner, $price, $note, $outsideSchedule) {
            $this->check($current, BookingStatus::Confirmed, $owner);

            $confirmedPrice = $this->price($price, $current->estimated_price);

            if (! $this->conflicts->isFree($current->starts_at, $current->ends_at, $current->id)) {
                throw BookingTransitionException::conflict();
            }

            // A time that has already passed is never confirmed, not even "anyway": the studio rejects or cancels it
            if ($current->starts_at->lte(Carbon::now('UTC'))) {
                throw BookingTransitionException::timePassed();
            }

            $reasons = $this->availability->ruleViolations($current->durationMinutes(), $current->starts_at);

            // The daily limit counts the other bookings that hold time on that day; "Confirm anyway" sets it aside too
            if ($this->availability->dayIsFull($current->starts_at, $current->id)) {
                $reasons[] = AvailabilityService::dailyLimitReason();
            }

            if ($reasons !== [] && ! $outsideSchedule) {
                throw BookingTransitionException::outsideSchedule($reasons);
            }

            if ($reasons !== []) {
                $note = trim('Confirmed outside the usual schedule: '.implode(' ', $reasons).' '.($this->clean($note) ?? ''));
            }

            $current->confirmed_price = $confirmedPrice;
            $current->hold_expires_at = null;
            [$to, $note] = $this->booked($current, $note);
            $this->move($current, $to, $owner, $note);
        }, schedule: true);
    }

    /**
     * Where a confirmation lands (Phase 10B): "Awaiting downpayment" with its deadline when the price needs one, else
     * Confirmed. Returns the status and the history note (the downpayment and its deadline added to it).
     *
     * @return array{0: BookingStatus, 1: ?string}
     */
    private function booked(PhotographyBooking $current, ?string $note): array
    {
        if (! $this->rules->needsDownpayment($current->confirmed_price)) {
            $current->downpayment_due_at = null;

            return [BookingStatus::Confirmed, $note];
        }

        $current->downpayment_due_at = $this->rules->dueAt($current->starts_at, Carbon::now('UTC'));
        $due = 'Downpayment of '.Money::cents($this->rules->downpaymentCents($current->confirmed_price)).' due by '.LocalTime::format($current->downpayment_due_at).'.';

        return [BookingStatus::AwaitingDownpayment, trim($due.' '.($this->clean($note) ?? ''))];
    }

    /**
     * The studio offers another time. It must be a free slot under the same rules as a new request. For a package
     * priced on request the studio names the price with the offer, and it is saved as the confirmed price.
     * The proposed time is HELD for the customer (Phase 10A) for the owner's "request hold" hours, under the schedule
     * lock; the time first asked for is given up.
     */
    public function propose(PhotographyBooking $booking, User $owner, CarbonInterface $startsAt, string $note, ?string $price = null): PhotographyBooking
    {
        $note = $this->clean($note) ?? throw BookingTransitionException::noteRequired();

        return $this->locked($booking, $owner, function (PhotographyBooking $current) use ($owner, $startsAt, $note, $price) {
            $this->check($current, BookingStatus::Proposed, $owner);

            // Phase 10I: a proposed time follows the same minimum notice as an online request
            if ($refusal = $this->availability->noticeRefusal($startsAt)) {
                throw BookingTransitionException::tooSoon($refusal);
            }

            if (! $this->availability->isAvailable($current->durationMinutes(), $startsAt, $current->id)) {
                throw BookingTransitionException::slotNotAvailable();
            }

            if ($current->estimated_price === null || $price !== null) {
                $current->confirmed_price = $this->price($price, $current->estimated_price);
            }

            $current->proposed_starts_at = Carbon::instance($startsAt)->utc();
            $current->proposed_note = $note;
            $current->hold_expires_at = BookingSetting::current()->holdUntil();
            $this->move($current, BookingStatus::Proposed, $owner, $note, BookingEvent::ProposalSent);
        }, schedule: true);
    }

    /**
     * The customer accepts the time the studio proposed: the booking moves to it and is confirmed. The time is checked
     * again against the schedule rules as they are now, so a day the studio blocked or hours it changed after proposing
     * are respected.
     */
    public function acceptProposal(PhotographyBooking $booking, User $customer): PhotographyBooking
    {
        return $this->locked($booking, $customer, function (PhotographyBooking $current) use ($customer) {
            $this->check($current, BookingStatus::Confirmed, $customer);

            $start = $current->proposed_starts_at ?? throw BookingTransitionException::noProposal();
            $end = $start->copy()->addMinutes($current->durationMinutes());

            if (! $this->conflicts->isFree($start, $end, $current->id)) {
                throw BookingTransitionException::conflict();
            }

            if (! $this->availability->isAvailable($current->durationMinutes(), $start, $current->id)) {
                throw BookingTransitionException::slotNotAvailable();
            }

            $current->starts_at = $start;
            $current->ends_at = $end;
            $current->confirmed_price ??= $current->estimated_price;
            $note = 'Moved to '.LocalTime::format($start).'.';
            $current->proposed_starts_at = null;
            $current->proposed_note = null;
            $current->hold_expires_at = null;
            [$to, $note] = $this->booked($current, $note);
            $this->move($current, $to, $customer, $note, BookingEvent::ProposalAccepted);
        }, schedule: true);
    }

    /**
     * The customer turns down the proposed time and asks for another one: the booking is a request again, at the time
     * first asked for. It holds that time again (a fresh hold) only when the time is still ahead and nobody else holds
     * it now; otherwise it waits without a hold and the studio proposes again.
     */
    public function askForAnotherTime(PhotographyBooking $booking, User $customer, string $note): PhotographyBooking
    {
        $note = $this->clean($note) ?? throw BookingTransitionException::noteRequired();

        return $this->locked($booking, $customer, function (PhotographyBooking $current) use ($customer, $note) {
            $this->check($current, BookingStatus::Requested, $customer);

            $current->proposed_starts_at = null;
            $current->proposed_note = null;
            $current->confirmed_price = null;
            $current->hold_expires_at = $current->starts_at->gt(Carbon::now('UTC')) && $this->conflicts->isFree($current->starts_at, $current->ends_at, $current->id)
                ? BookingSetting::current()->holdUntil()
                : null;
            $this->move($current, BookingStatus::Requested, $customer, $note, BookingEvent::TimeRequestedAgain);
        }, schedule: true);
    }

    public function reject(PhotographyBooking $booking, User $owner, string $reason): PhotographyBooking
    {
        $reason = $this->clean($reason) ?? throw BookingTransitionException::reasonRequired();

        return $this->locked($booking, $owner, function (PhotographyBooking $current) use ($owner, $reason) {
            $this->check($current, BookingStatus::Rejected, $owner);

            $current->rejection_reason = $reason;
            $current->proposed_starts_at = null;
            $current->proposed_note = null;
            $current->hold_expires_at = null;
            $this->move($current, BookingStatus::Rejected, $owner, $reason);
        });
    }

    public const KEEP_PAYMENT = 'keep';

    public const REFUND_PAYMENT = 'refund';

    /**
     * Either side cancels. A customer may cancel a confirmed booking only until config
     * booking.customer_cancel_cutoff_hours before it starts; requests, proposals and a booking awaiting its downpayment
     * at any time. A downpayment the customer paid stays recorded (it is non-refundable, and it stays income).
     *
     * Phase 10B: when the SHOP cancels a booking with recorded payments it must say what happens to the money:
     * KEEP_PAYMENT (not refunded) or REFUND_PAYMENT (every payment is voided as a refund, with $refundReason, in this
     * transaction). The preset reason "Did not show up" is accepted only after the shoot's start time.
     */
    public function cancel(PhotographyBooking $booking, User $actor, ?string $reason = null, ?string $paymentChoice = null, ?string $refundReason = null): PhotographyBooking
    {
        $reason = $this->clean($reason);
        $refundReason = $this->clean($refundReason);

        return $this->locked($booking, $actor, function (PhotographyBooking $current) use ($actor, $reason, $paymentChoice, $refundReason) {
            $this->check($current, BookingStatus::Cancelled, $actor);

            $cutoff = (int) config('booking.customer_cancel_cutoff_hours');

            if ($actor->role === UserRole::Customer && $current->status === BookingStatus::Confirmed && Carbon::now('UTC')->gt($current->starts_at->copy()->subHours($cutoff))) {
                throw BookingTransitionException::tooLateToCancel($cutoff);
            }

            if ($reason !== null && strcasecmp($reason, (string) config('payments.no_show_reason')) === 0 && $current->starts_at->gt(Carbon::now('UTC'))) {
                throw BookingTransitionException::noShowTooEarly();
            }

            $paid = $actor->role === UserRole::Owner && Payment::recordedFor($current)->exists();

            if ($paid && ! in_array($paymentChoice, [self::KEEP_PAYMENT, self::REFUND_PAYMENT], true)) {
                throw BookingTransitionException::paymentChoiceRequired();
            }

            if ($paid && $paymentChoice === self::REFUND_PAYMENT && $refundReason === null) {
                throw BookingTransitionException::refundReasonRequired();
            }

            $current->cancellation_reason = $reason;
            $current->proposed_starts_at = null;
            $current->proposed_note = null;
            $current->hold_expires_at = null;
            $note = $paid ? trim(($reason ?? '').' '.($paymentChoice === self::REFUND_PAYMENT ? 'The payment was refunded.' : 'The payment is kept (not refunded).')) : $reason;
            $this->move($current, BookingStatus::Cancelled, $actor, $note);

            if ($paid && $paymentChoice === self::REFUND_PAYMENT) {
                app(PaymentService::class)->refundAll($current, $actor, $refundReason);
            }
        });
    }

    /**
     * The studio waives the downpayment (Phase 10B): the booking is Confirmed and the full price is due on the day of
     * the shoot. A reason is required; it is audited.
     */
    public function waiveDownpayment(PhotographyBooking $booking, User $owner, string $reason): PhotographyBooking
    {
        $reason = $this->clean($reason) ?? throw BookingTransitionException::reasonRequired();

        return $this->locked($booking, $owner, function (PhotographyBooking $current) use ($owner, $reason) {
            $this->ownerOnly($owner);

            if ($current->status !== BookingStatus::AwaitingDownpayment) {
                throw BookingTransitionException::notAwaitingDownpayment($current->status);
            }

            $current->downpayment_due_at = null;
            $this->move($current, BookingStatus::Confirmed, $owner, 'Downpayment waived: '.$reason.' The full price is paid on the day of the shoot.', BookingEvent::DownpaymentWaived);
            app(AuditLogger::class)->record(AuditAction::BookingDownpaymentWaived, $current, ['reason' => $reason], actor: $owner);
        });
    }

    /**
     * The studio gives the customer more time to pay the downpayment (Phase 10B): a new deadline after now and no later
     * than the shoot start, with a reason. Under the schedule lock: a deadline that had already passed held nothing, so
     * the time must still be free.
     */
    public function extendDeadline(PhotographyBooking $booking, User $owner, CarbonInterface $dueAt, string $reason): PhotographyBooking
    {
        $reason = $this->clean($reason) ?? throw BookingTransitionException::reasonRequired();
        $due = Carbon::instance($dueAt)->utc()->startOfMinute();

        return $this->locked($booking, $owner, function (PhotographyBooking $current) use ($owner, $due, $reason) {
            $this->ownerOnly($owner);

            if ($current->status !== BookingStatus::AwaitingDownpayment) {
                throw BookingTransitionException::notAwaitingDownpayment($current->status);
            }

            if ($due->lte(Carbon::now('UTC')) || $due->gt($current->starts_at)) {
                throw BookingTransitionException::deadlineOutOfRange();
            }

            if (! $this->conflicts->isFree($current->starts_at, $current->ends_at, $current->id)) {
                throw BookingTransitionException::conflict();
            }

            $old = $current->downpayment_due_at;
            $current->downpayment_due_at = $due;
            $current->save();

            $this->writeHistory($current, $current->status, $current->status, $owner, 'New downpayment deadline: '.LocalTime::format($due).'. '.$reason, BookingEvent::DeadlineExtended, ['due' => $due->toIso8601ZuluString()]);
            app(AuditLogger::class)->record(AuditAction::BookingDeadlineExtended, $current, ['due' => LocalTime::format($due), 'old_due' => $old ? LocalTime::format($old) : null, 'reason' => $reason], actor: $owner);
        }, schedule: true);
    }

    /**
     * Cancels a booking whose downpayment was not paid by its deadline (Phase 10B). The SYSTEM acts: no person, the
     * history reads "System". Idempotent and safe against a payment at the same moment: the booking row is locked and
     * read again, and only a booking still "Awaiting downpayment", past its deadline and with no recorded payment is
     * cancelled. Returns whether it cancelled it.
     */
    public function expire(PhotographyBooking $booking): bool
    {
        return DB::transaction(function () use ($booking) {
            $current = PhotographyBooking::query()->lockForUpdate()->find($booking->getKey());

            if ($current === null || $current->status !== BookingStatus::AwaitingDownpayment || $current->downpayment_due_at === null
                || $current->downpayment_due_at->gt(Carbon::now('UTC')) || Payment::recordedFor($current)->exists()) {
                return false;
            }

            $current->cancellation_reason = (string) config('payments.expired_reason');
            $current->hold_expires_at = null;
            $this->move($current, BookingStatus::Cancelled, null, $current->cancellation_reason, BookingEvent::DownpaymentExpired);
            app(AuditLogger::class)->recordAsSystem(AuditAction::BookingDownpaymentExpired, $current);

            $booking->setRawAttributes($current->getAttributes(), true);

            return true;
        });
    }

    /**
     * The downpayment or the full price was recorded on a booking awaiting its downpayment: it is Confirmed now. Called by
     * PaymentService inside its own locked transaction (the payment's history row tells the customer).
     */
    public function confirmByPayment(PhotographyBooking $current, User $owner, Payment $payment): void
    {
        $current->downpayment_due_at = null;
        $this->move($current, BookingStatus::Confirmed, $owner, 'Confirmed: '.strtolower($payment->kind->label()).' '.$payment->reference_number.' recorded.', BookingEvent::ConfirmedByPayment);
    }

    /**
     * The downpayment of a Confirmed booking was voided: it waits for a downpayment again, until $dueAt. Called by
     * PaymentService inside its own locked transaction.
     */
    public function backToDownpayment(PhotographyBooking $current, User $owner, CarbonInterface $dueAt): void
    {
        $current->downpayment_due_at = Carbon::instance($dueAt)->utc();
        $note = 'The downpayment was voided. A downpayment of '.Money::cents($this->rules->downpaymentCents((string) $current->confirmed_price)).' is due by '.LocalTime::format($current->downpayment_due_at).'.';
        $this->move($current, BookingStatus::AwaitingDownpayment, $owner, $note, BookingEvent::BackToDownpayment, ['due' => $current->downpayment_due_at->toIso8601ZuluString()]);
    }

    /** The studio marks the photoshoot done, never before its start time. */
    public function complete(PhotographyBooking $booking, User $owner, ?string $note = null): PhotographyBooking
    {
        return $this->locked($booking, $owner, function (PhotographyBooking $current) use ($owner, $note) {
            $this->check($current, BookingStatus::Completed, $owner);

            if ($current->starts_at->gt(Carbon::now('UTC'))) {
                throw BookingTransitionException::notStartedYet();
            }

            // The release gate: the photos are released (Completed) only when the shoot is fully paid, or costs nothing
            if (! PaymentStatus::derive($current->confirmed_price, $this->paidCents($current))->isSettled()) {
                throw BookingTransitionException::notPaid();
            }

            $this->move($current, BookingStatus::Completed, $owner, $this->clean($note));
        });
    }

    /** The studio changes the confirmed price of a confirmed booking. The same price twice writes nothing. */
    public function changePrice(PhotographyBooking $booking, User $owner, string $price, ?string $reason = null): PhotographyBooking
    {
        $reason = $this->clean($reason);

        return $this->locked($booking, $owner, function (PhotographyBooking $current) use ($owner, $price, $reason) {
            $this->ownerOnly($owner);

            if (! $current->status->allowsPriceChange()) {
                throw BookingTransitionException::priceChangeNotAllowed($current->status);
            }

            if (Payment::recordedFor($current)->exists()) {
                throw BookingTransitionException::priceLocked();
            }

            $new = Money::normalizePrice($price) ?? throw BookingTransitionException::priceOutOfRange();
            $old = $current->confirmed_price;

            if ($old === $new) {
                return;
            }

            $current->confirmed_price = $new;
            $current->save();

            $note = 'Confirmed price changed from '.Money::format($old).' to '.Money::format($new).'.'.($reason ? ' '.$reason : '');
            $this->writeHistory($current, $current->status, $current->status, $owner, $note, BookingEvent::PriceChanged);

            // Phase 10B: a new price that needs no downpayment (for example ₱0.00) confirms a booking waiting for one
            if ($current->status === BookingStatus::AwaitingDownpayment && ! $this->rules->needsDownpayment($new)) {
                $current->downpayment_due_at = null;
                $this->move($current, BookingStatus::Confirmed, $owner, 'No downpayment is due at this price.');
            }
            app(AuditLogger::class)->record(AuditAction::BookingPriceChanged, $current, ['old_cents' => AuditLogger::cents($old), 'new_cents' => AuditLogger::cents($new), 'reason' => $reason], actor: $owner);
        });
    }

    /**
     * Writes the history row of a payment recorded or voided on the booking. Called by PaymentService inside its own
     * locked transaction; the status does not change.
     */
    /** @param  array{payment?: string, amount?: string}  $context  for the customer's notification (never the GCash reference) */
    public function recordPaymentEvent(PhotographyBooking $booking, User $owner, BookingEvent $event, ?string $note, array $context = []): void
    {
        $this->writeHistory($booking, $booking->status, $booking->status, $owner, $note, $event, $context);
    }

    /**
     * The studio's private note on a booking. Customers never see it, so no history row is written (the history is
     * shown to customers). Empty text clears the note.
     */
    public function saveInternalNote(PhotographyBooking $booking, User $owner, ?string $text): PhotographyBooking
    {
        $this->ownerOnly($owner);
        $text = $this->clean($text);

        if ($text !== null && mb_strlen($text) > self::INTERNAL_NOTE_MAX) {
            throw BookingTransitionException::internalNoteTooLong(self::INTERNAL_NOTE_MAX);
        }

        return $this->locked($booking, $owner, function (PhotographyBooking $current) use ($text) {
            $current->internal_notes = $text;
            $current->save();
        });
    }

    /** The table check: the move exists and this role may make it. */
    private function check(PhotographyBooking $current, BookingStatus $to, User $actor): void
    {
        $from = $current->status;

        if (! array_key_exists($to->value, $from->transitions())) {
            throw BookingTransitionException::notAllowed($from, $to);
        }

        if (! $from->canMoveTo($to, $actor->role)) {
            throw BookingTransitionException::notPermitted($from, $to);
        }
    }

    /** @param  array{due?: string}  $context */
    private function move(PhotographyBooking $current, BookingStatus $to, ?User $actor, ?string $note, ?BookingEvent $event = null, array $context = []): void
    {
        $from = $current->status;
        $current->status = $to;
        $current->save();

        // The deadline goes with the notice the customer reads when a booking starts waiting for its downpayment
        if ($to === BookingStatus::AwaitingDownpayment && $current->downpayment_due_at !== null) {
            $context += ['due' => $current->downpayment_due_at->toIso8601ZuluString(), 'amount' => Money::fromCents($this->rules->downpaymentCents((string) $current->confirmed_price))];
        }

        $this->writeHistory($current, $from, $to, $actor, $note, $event, $context);
    }

    /** What the recorded payments add up to, in centavos (read inside the locked transaction). */
    private function paidCents(PhotographyBooking $current): int
    {
        return (int) Payment::recordedFor($current)->get(['amount'])->sum(fn (Payment $payment) => Money::toCents($payment->amount));
    }

    /**
     * Runs a change on the booking row, locked and read again inside one transaction, then hands the caller's own
     * instance the saved values. With $schedule the schedule lock is taken first (always in that order).
     *
     * @param  Closure(PhotographyBooking): void  $change
     */
    private function locked(PhotographyBooking $booking, User $actor, Closure $change, bool $schedule = false): PhotographyBooking
    {
        return DB::transaction(function () use ($booking, $actor, $change, $schedule) {
            if ($schedule) {
                $this->takeScheduleLock();
            }

            $current = PhotographyBooking::query()->lockForUpdate()->findOrFail($booking->getKey());

            if ($actor->role === UserRole::Customer && $current->customer_id !== $actor->id) {
                throw new AuthorizationException('This booking belongs to another customer.');
            }

            $change($current);

            $booking->setRawAttributes($current->getAttributes(), true);

            return $booking;
        });
    }

    /**
     * Held until the transaction ends. The settings row is created from the config defaults if it is missing. The
     * UPDATE comes first (Phase 9B, found on MySQL): an INSERT IGNORE on the existing row takes a SHARED lock, and two
     * confirms holding it while both wait for the exclusive one deadlock (1213). Insert only when the row is missing.
     */
    private function takeScheduleLock(): void
    {
        if (DB::table('booking_settings')->where('id', BookingSetting::ROW_ID)->increment('lock_version') === 0) {
            DB::table('booking_settings')->insertOrIgnore(['id' => BookingSetting::ROW_ID] + config('booking.defaults') + ['lock_version' => 0, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('booking_settings')->where('id', BookingSetting::ROW_ID)->increment('lock_version');
        }
    }

    private function ownerOnly(User $actor): void
    {
        if ($actor->role !== UserRole::Owner) {
            throw new AuthorizationException('Only the studio can do this.');
        }
    }

    /** The price typed, or the estimate when nothing was typed; a package priced on request needs one typed. */
    private function price(?string $typed, ?string $estimate): string
    {
        $typed = $this->clean($typed);

        if ($typed === null) {
            return $estimate ?? throw BookingTransitionException::priceRequired();
        }

        return Money::normalizePrice($typed) ?? throw BookingTransitionException::priceOutOfRange();
    }

    private function clean(?string $text): ?string
    {
        $text = $text === null ? null : trim($text);

        return $text === '' ? null : $text;
    }

    /**
     * Every history row is written here, and only here the notifications start (NotificationDispatcher decides who is
     * told, after the transaction commits): no history row, no notification.
     *
     * A null $actor is the system (Phase 10B): the row's changed_by stays empty and reads "System".
     *
     * @param  array{payment?: string, amount?: string, kind?: string, due?: string}  $context
     */
    private function writeHistory(PhotographyBooking $booking, ?BookingStatus $from, BookingStatus $to, ?User $actor, ?string $note, ?BookingEvent $event = null, array $context = []): void
    {
        $history = new BookingStatusHistory;
        $history->forceFill([
            'photography_booking_id' => $booking->getKey(),
            'from_status' => $from,
            'to_status' => $to,
            'event' => $event,
            'changed_by' => $actor?->getKey(),
            'note' => $note,
        ])->save();

        // Audit trail (8E): every real status move (a proposal, an acceptance, a new time asked are moves too), in the
        // action's transaction; payments and prices log themselves
        if ($actor !== null && $from !== null && $from !== $to && ! in_array($event, [BookingEvent::PaymentRecorded, BookingEvent::PaymentVoided, BookingEvent::PriceChanged, BookingEvent::DownpaymentWaived], true)) {
            app(AuditLogger::class)->record(match ($to) {
                BookingStatus::Cancelled => AuditAction::BookingCancelled,
                BookingStatus::Rejected => AuditAction::BookingRejected,
                BookingStatus::Completed => AuditAction::BookingCompleted,
                default => AuditAction::BookingStatusChanged,
            }, $booking, array_filter(['from' => $from->value, 'to' => $to->value,
                'price_cents' => $to === BookingStatus::Confirmed ? AuditLogger::cents($booking->confirmed_price) : null,
                'reason' => in_array($to, [BookingStatus::Cancelled, BookingStatus::Rejected], true) ? $note : null,
            ], fn ($value) => $value !== null), actor: $actor);
        }

        $this->notifications->bookingHistory($booking, $from, $to, $event, $actor, $context);
    }
}
