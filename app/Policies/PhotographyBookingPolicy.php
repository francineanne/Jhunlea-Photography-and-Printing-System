<?php

namespace App\Policies;

use App\Enums\BookingStatus;
use App\Enums\UserRole;
use App\Models\PhotographyBooking;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Who may do what with a photography booking. The owner sees every booking. A customer sees and acts only on their
 * own (a denied check answers 403). Which moves exist is BookingStatus::transitions(); BookingTransitionService checks
 * everything again inside its transaction, so a page that is out of date cannot get through.
 */
class PhotographyBookingPolicy
{
    /** The list pages show each user only their own rows (customers) or everything (owner); the query does the filtering. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, PhotographyBooking $booking): bool
    {
        return $this->isOwner($user) || $this->owns($user, $booking);
    }

    /** Only customers request photoshoots. */
    public function create(User $user): bool
    {
        return $user->role === UserRole::Customer;
    }

    /** A customer cancels their own request or proposal at any time, and a confirmed booking until the cutoff. */
    public function cancel(User $user, PhotographyBooking $booking): bool
    {
        if ($this->isOwner($user)) {
            return $booking->status->canMoveTo(BookingStatus::Cancelled, UserRole::Owner);
        }

        if (! $this->owns($user, $booking) || ! $booking->status->canMoveTo(BookingStatus::Cancelled, UserRole::Customer)) {
            return false;
        }

        return $booking->status !== BookingStatus::Confirmed
            || Carbon::now('UTC')->lte($booking->starts_at->copy()->subHours((int) config('booking.customer_cancel_cutoff_hours')));
    }

    /** Accept the proposed time or ask for another: the customer whose booking it is, while a time is proposed. */
    public function respondToProposal(User $user, PhotographyBooking $booking): bool
    {
        return $this->owns($user, $booking) && $booking->status === BookingStatus::Proposed;
    }

    /** Prints after the shoot: the customer whose booking it is, once the photoshoot is completed. */
    public function orderPrints(User $user, PhotographyBooking $booking): bool
    {
        return $this->owns($user, $booking) && $booking->status->allowsPrintOrders();
    }

    /** Confirm, propose, reject, complete, change the price: the studio's work. */
    public function changeStatus(User $user, PhotographyBooking $booking): bool
    {
        return $this->isOwner($user);
    }

    private function isOwner(User $user): bool
    {
        return $user->role === UserRole::Owner;
    }

    private function owns(User $user, PhotographyBooking $booking): bool
    {
        return $user->role === UserRole::Customer && $booking->customer_id === $user->id;
    }
}
