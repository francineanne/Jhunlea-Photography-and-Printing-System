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
 * PaymentService takes only 3 and 4 (booking row, then the PAY counter), never the schedule lock.
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
        $this->writeHistory($booking, null, BookingStatus::Confirmed, $owner, $note);
        app(AuditLogger::class)->record(AuditAction::BookingWalkInCreated, $booking, ['price_cents' => AuditLogger::cents($booking->confirmed_price), 'customer' => $booking->customer?->name], actor: $owner);
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
            $this->move($current, BookingStatus::Confirmed, $owner, $note);
        }, schedule: true);
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
            $this->move($current, BookingStatus::Confirmed, $customer, $note, BookingEvent::ProposalAccepted);
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

    /**
     * Either side cancels. A customer may cancel a confirmed booking only until config
     * booking.customer_cancel_cutoff_hours before it starts; requests and proposals at any time.
     */
    public function cancel(PhotographyBooking $booking, User $actor, ?string $reason = null): PhotographyBooking
    {
        $reason = $this->clean($reason);

        return $this->locked($booking, $actor, function (PhotographyBooking $current) use ($actor, $reason) {
            $this->check($current, BookingStatus::Cancelled, $actor);

            $cutoff = (int) config('booking.customer_cancel_cutoff_hours');

            if ($actor->role === UserRole::Customer && $current->status === BookingStatus::Confirmed && Carbon::now('UTC')->gt($current->starts_at->copy()->subHours($cutoff))) {
                throw BookingTransitionException::tooLateToCancel($cutoff);
            }

            $current->cancellation_reason = $reason;
            $current->proposed_starts_at = null;
            $current->proposed_note = null;
            $current->hold_expires_at = null;
            $this->move($current, BookingStatus::Cancelled, $actor, $reason);
        });
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
            if (! PaymentStatus::derive($current->confirmed_price, Payment::recordedFor($current)->exists())->isSettled()) {
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

    private function move(PhotographyBooking $current, BookingStatus $to, User $actor, ?string $note, ?BookingEvent $event = null): void
    {
        $from = $current->status;
        $current->status = $to;
        $current->save();
        $this->writeHistory($current, $from, $to, $actor, $note, $event);
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
     * @param  array{payment?: string, amount?: string}  $context
     */
    private function writeHistory(PhotographyBooking $booking, ?BookingStatus $from, BookingStatus $to, User $actor, ?string $note, ?BookingEvent $event = null, array $context = []): void
    {
        $history = new BookingStatusHistory;
        $history->forceFill([
            'photography_booking_id' => $booking->getKey(),
            'from_status' => $from,
            'to_status' => $to,
            'event' => $event,
            'changed_by' => $actor->getKey(),
            'note' => $note,
        ])->save();

        // Audit trail (8E): every real status move (a proposal, an acceptance, a new time asked are moves too), in the
        // action's transaction; payments and prices log themselves
        if ($from !== null && $from !== $to && ! in_array($event, [BookingEvent::PaymentRecorded, BookingEvent::PaymentVoided, BookingEvent::PriceChanged], true)) {
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
