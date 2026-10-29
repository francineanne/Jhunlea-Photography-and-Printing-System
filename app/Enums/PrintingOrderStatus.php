<?php

namespace App\Enums;

enum PrintingOrderStatus: string
{
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Processing = 'processing';
    case ReadyForPickup = 'ready_for_pickup';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Submitted',
            self::UnderReview => 'Under review',
            self::Accepted => 'Accepted',
            self::Rejected => 'Rejected',
            self::Processing => 'Processing',
            self::ReadyForPickup => 'Ready for pickup',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * The only table of allowed moves: from this status, to which statuses, and which role may make each move.
     * StatusTransitionService reads it and nothing else decides. A customer may only cancel, and only while the
     * order is still Submitted. A status that is missing here is final.
     *
     * @return array<string, list<UserRole>>
     */
    public function transitions(): array
    {
        $owner = [UserRole::Owner];

        return match ($this) {
            self::Submitted => [
                self::UnderReview->value => $owner,
                self::Cancelled->value => [UserRole::Owner, UserRole::Customer],
            ],
            self::UnderReview => [
                self::Accepted->value => $owner,
                self::Rejected->value => $owner,
                self::Cancelled->value => $owner,
            ],
            self::Accepted => [
                self::Processing->value => $owner,
                self::Cancelled->value => $owner,
            ],
            self::Processing => [
                self::ReadyForPickup->value => $owner,
            ],
            self::ReadyForPickup => [
                self::Completed->value => $owner,
            ],
            self::Rejected, self::Completed, self::Cancelled => [],
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

    /** Statuses where the customer may still add or replace files. */
    public function acceptsFiles(): bool
    {
        return in_array($this, [self::Submitted, self::UnderReview], true);
    }

    /** The shop may ask for more information only while the customer can still answer it by adding files. */
    public function allowsInfoRequest(): bool
    {
        return $this->acceptsFiles();
    }

    /** The owner may change the confirmed price after accepting, until the prints are ready. */
    public function allowsPriceChange(): bool
    {
        return in_array($this, [self::Accepted, self::Processing], true);
    }

    /** A payment can be recorded once the order is accepted (it has a confirmed price), and never on a closed order. */
    public function takesPayment(): bool
    {
        return in_array($this, [self::Accepted, self::Processing, self::ReadyForPickup, self::Completed], true);
    }

    /** Rejected or cancelled: nothing is due, so no payment is recorded. */
    public function isClosed(): bool
    {
        return in_array($this, [self::Rejected, self::Cancelled], true);
    }
}
