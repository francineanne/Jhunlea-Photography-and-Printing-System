<?php

namespace App\Policies;

use App\Enums\PrintingOrderStatus;
use App\Enums\UserRole;
use App\Models\PrintingOrder;
use App\Models\User;

/**
 * Who may do what with a printing order. The owner sees every order. A customer sees and changes only their own.
 * A denied check answers 403; only a route that cannot find the record at all answers 404.
 */
class PrintingOrderPolicy
{
    /** The list pages show each user only their own rows (customers) or everything (owner); the query does the filtering. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, PrintingOrder $order): bool
    {
        return $this->isOwner($user) || $this->owns($user, $order);
    }

    /** Only customers place orders. The owner does not order through the customer form. */
    public function create(User $user): bool
    {
        return $user->role === UserRole::Customer;
    }

    /** A customer may cancel their own order only while it is still Submitted. */
    public function cancel(User $user, PrintingOrder $order): bool
    {
        return $this->owns($user, $order) && $order->status->canMoveTo(PrintingOrderStatus::Cancelled, UserRole::Customer);
    }

    /** Accept, reject, process, mark ready, complete: the owner's work. Which moves are possible is up to the status table. */
    public function changeStatus(User $user, PrintingOrder $order): bool
    {
        return $this->isOwner($user);
    }

    /** Add or replace files: the customer on their own order while it is still open, and the owner. */
    public function addFiles(User $user, PrintingOrder $order): bool
    {
        if (! $order->status->acceptsFiles()) {
            return false;
        }

        return $this->isOwner($user) || $this->owns($user, $order);
    }

    private function isOwner(User $user): bool
    {
        return $user->role === UserRole::Owner;
    }

    private function owns(User $user, PrintingOrder $order): bool
    {
        return $user->role === UserRole::Customer && $order->customer_id === $user->id;
    }
}
