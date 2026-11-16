<?php

namespace App\Enums;

/**
 * A photography booking is a request until the studio confirms it. Only the studio confirms a requested time; the
 * customer confirms only by accepting a time the studio proposed.
 */
enum BookingStatus: string
{
    case Requested = 'requested';
    case Proposed = 'proposed';
    // Phase 10B: confirmed by the studio, held until the downpayment deadline; the downpayment (or the full price)
    // makes it Confirmed. Never at the counter
    case AwaitingDownpayment = 'awaiting_downpayment';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Requested',
            self::Proposed => 'New time proposed',
            self::AwaitingDownpayment => 'Awaiting downpayment',
            self::Confirmed => 'Confirmed',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
            self::Completed => 'Completed',
        };
    }

    /**
     * The only table of allowed moves: from this status, to which statuses, and which role may make each move.
     * BookingTransitionService reads it and nothing else decides. A status that is missing here is final.
     *
     * Customer moves: cancel (a confirmed booking only until config booking.customer_cancel_cutoff_hours before it
     * starts, checked by the service), accept a proposed time (proposed to confirmed) and ask for another time
     * (proposed to requested). The customer never moves requested to confirmed.
     *
     * Phase 10B: confirming (or accepting a proposal) goes to Awaiting downpayment when a downpayment is due. From there
     * the booking becomes Confirmed when the owner records the downpayment or the full price, or waives the downpayment
     * (PaymentService and BookingTransitionService make that move for the owner, in the same transaction), and is
     * Cancelled by either side or by the system when the deadline passes. Voiding the downpayment of a Confirmed booking
     * sends it back (Confirmed to Awaiting downpayment, owner).
     *
     * @return array<string, list<UserRole>>
     */
    public function transitions(): array
    {
        $owner = [UserRole::Owner];
        $customer = [UserRole::Customer];
        $both = [UserRole::Owner, UserRole::Customer];

        return match ($this) {
            self::Requested => [
                self::AwaitingDownpayment->value => $owner,
                self::Confirmed->value => $owner,
                self::Proposed->value => $owner,
                self::Rejected->value => $owner,
                self::Cancelled->value => $both,
            ],
            self::Proposed => [
                self::AwaitingDownpayment->value => $customer,
                self::Confirmed->value => $customer,
                self::Requested->value => $customer,
                self::Cancelled->value => $both,
                self::Rejected->value => $owner,
            ],
            self::AwaitingDownpayment => [
                self::Confirmed->value => $owner,
                self::Cancelled->value => $both,
            ],
            self::Confirmed => [
                self::AwaitingDownpayment->value => $owner,
                self::Completed->value => $owner,
                self::Cancelled->value => $both,
            ],
            self::Rejected, self::Cancelled, self::Completed => [],
        };
    }

    public function canMoveTo(self $to, UserRole $role): bool
    {
        return in_array($role, $this->transitions()[$to->value] ?? [], true);
    }

    public function isFinal(): bool
    {
        return $this->transitions() === [];
    }

    /**
     * Whether a booking in this status ALWAYS holds its time on the schedule. Confirmed bookings do. Completed bookings
     * also do, so a shoot that is marked done early still holds the rest of its time and history queries count it.
     * Since Phase 10A a Requested or Proposed booking holds time too, but only while its hold lasts: the whole rule is
     * PhotographyBooking::scopeHoldingTime() / holdsTime(), which use this for the statuses that hold without a hold.
     */
    public function blocksSlot(): bool
    {
        return in_array($this, [self::Confirmed, self::Completed], true);
    }

    /** @return list<self> */
    public static function blocking(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status) => $status->blocksSlot()));
    }

    /** Prints can be ordered from a shoot only once it is done: before that there are no photos to print. */
    public function allowsPrintOrders(): bool
    {
        return $this === self::Completed;
    }

    /** Confirmed by the studio and on the schedule (or waiting for its downpayment): a session the shop plans for. */
    public function isBooked(): bool
    {
        return in_array($this, [self::AwaitingDownpayment, self::Confirmed], true);
    }

    /** Reference images can be added while the studio has not fixed the session yet. */
    public function acceptsImages(): bool
    {
        return in_array($this, [self::Requested, self::Proposed], true);
    }

    /** The confirmed price may change only while the session is still ahead (and no payment is recorded). */
    public function allowsPriceChange(): bool
    {
        return in_array($this, [self::AwaitingDownpayment, self::Confirmed], true);
    }

    /**
     * A photoshoot is paid on the day of the shoot, so a payment can be recorded once the session is confirmed (its time
     * and price are fixed). Before that the price can still change or be cleared by a new time request.
     */
    public function takesPayment(): bool
    {
        return in_array($this, [self::AwaitingDownpayment, self::Confirmed, self::Completed], true);
    }

    /** Rejected or cancelled: nothing is due, so no payment is recorded. */
    public function isClosed(): bool
    {
        return in_array($this, [self::Rejected, self::Cancelled], true);
    }
}
