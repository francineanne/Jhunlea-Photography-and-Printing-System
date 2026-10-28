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
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Requested',
            self::Proposed => 'New time proposed',
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
     * @return array<string, list<UserRole>>
     */
    public function transitions(): array
    {
        $owner = [UserRole::Owner];
        $customer = [UserRole::Customer];
        $both = [UserRole::Owner, UserRole::Customer];

        return match ($this) {
            self::Requested => [
                self::Confirmed->value => $owner,
                self::Proposed->value => $owner,
                self::Rejected->value => $owner,
                self::Cancelled->value => $both,
            ],
            self::Proposed => [
                self::Confirmed->value => $customer,
                self::Requested->value => $customer,
                self::Cancelled->value => $both,
                self::Rejected->value => $owner,
            ],
            self::Confirmed => [
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
     * Whether a booking in this status holds its time on the schedule. Confirmed bookings do. Completed bookings
     * also do, so a shoot that is marked done early still holds the rest of its time and history queries count it.
     * Requests and proposals never block other customers; the owner sees them as competing requests instead.
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

    /** Reference images can be added while the studio has not fixed the session yet. */
    public function acceptsImages(): bool
    {
        return in_array($this, [self::Requested, self::Proposed], true);
    }

    /** The confirmed price may change only while the session is still ahead. */
    public function allowsPriceChange(): bool
    {
        return $this === self::Confirmed;
    }
}
