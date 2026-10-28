<?php

namespace App\Services;

use App\Enums\BookingEvent;
use App\Enums\BookingStatus;
use App\Enums\UserRole;
use App\Exceptions\BookingTransitionException;
use App\Models\BookingSetting;
use App\Models\BookingStatusHistory;
use App\Models\PhotographyBooking;
use App\Models\User;
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
 * Moves that put a booking on the schedule (confirm, accept a proposal) first take the SCHEDULE LOCK: they bump the
 * single booking_settings row, which every such move must also update, so two of them run one after the other even
 * for two different bookings. The conflict check runs after the lock is held, so two overlapping confirms can never
 * both win. (An UPDATE locks the row on MySQL and takes the write lock on SQLite, so this works on both.)
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
    ) {}

    /** Writes the first history row of a new booking. Called by BookingService. */
    public function recordRequest(PhotographyBooking $booking, User $customer): void
    {
        $this->writeHistory($booking, null, BookingStatus::Requested, $customer, null);
    }

    /**
     * The studio confirms the time the customer asked for. The price defaults to the estimate and may be changed;
     * a package priced on request has no estimate, so the price is required.
     *
     * A clash with a confirmed booking always refuses. A time that now breaks one of the schedule rules (for example
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

            if ($reasons !== [] && ! $outsideSchedule) {
                throw BookingTransitionException::outsideSchedule($reasons);
            }

            if ($reasons !== []) {
                $note = trim('Confirmed outside the usual schedule: '.implode(' ', $reasons).' '.($this->clean($note) ?? ''));
            }

            $current->confirmed_price = $confirmedPrice;
            $this->move($current, BookingStatus::Confirmed, $owner, $note);
        }, schedule: true);
    }

    /**
     * The studio offers another time. It must be a free slot under the same rules as a new request. For a package
     * priced on request the studio names the price with the offer, and it is saved as the confirmed price.
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
            $this->move($current, BookingStatus::Proposed, $owner, $note, BookingEvent::ProposalSent);
        });
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
            $this->move($current, BookingStatus::Confirmed, $customer, $note, BookingEvent::ProposalAccepted);
        }, schedule: true);
    }

    /** The customer turns down the proposed time and asks for another one: the booking is a request again. */
    public function askForAnotherTime(PhotographyBooking $booking, User $customer, string $note): PhotographyBooking
    {
        $note = $this->clean($note) ?? throw BookingTransitionException::noteRequired();

        return $this->locked($booking, $customer, function (PhotographyBooking $current) use ($customer, $note) {
            $this->check($current, BookingStatus::Requested, $customer);

            $current->proposed_starts_at = null;
            $current->proposed_note = null;
            $current->confirmed_price = null;
            $this->move($current, BookingStatus::Requested, $customer, $note, BookingEvent::TimeRequestedAgain);
        });
    }

    public function reject(PhotographyBooking $booking, User $owner, string $reason): PhotographyBooking
    {
        $reason = $this->clean($reason) ?? throw BookingTransitionException::reasonRequired();

        return $this->locked($booking, $owner, function (PhotographyBooking $current) use ($owner, $reason) {
            $this->check($current, BookingStatus::Rejected, $owner);

            $current->rejection_reason = $reason;
            $current->proposed_starts_at = null;
            $current->proposed_note = null;
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

            $new = Money::normalizePrice($price) ?? throw BookingTransitionException::priceOutOfRange();
            $old = $current->confirmed_price;

            if ($old === $new) {
                return;
            }

            $current->confirmed_price = $new;
            $current->save();

            $note = 'Confirmed price changed from '.Money::format($old).' to '.Money::format($new).'.'.($reason ? ' '.$reason : '');
            $this->writeHistory($current, $current->status, $current->status, $owner, $note, BookingEvent::PriceChanged);
        });
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

    /** Held until the transaction ends. The settings row is created from the config defaults if it is missing. */
    private function takeScheduleLock(): void
    {
        DB::table('booking_settings')->insertOrIgnore(['id' => BookingSetting::ROW_ID] + config('booking.defaults') + ['lock_version' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('booking_settings')->where('id', BookingSetting::ROW_ID)->increment('lock_version');
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

    private function writeHistory(PhotographyBooking $booking, ?BookingStatus $from, BookingStatus $to, User $actor, ?string $note, ?BookingEvent $event = null): void
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
    }
}
