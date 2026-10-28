<?php

namespace App\Services;

use App\Enums\PrintingOrderStatus;
use App\Enums\UserRole;
use App\Exceptions\InvalidStatusTransitionException;
use App\Models\OrderStatusHistory;
use App\Models\PrintingOrder;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * The only code that changes the status of a printing order. Which moves are allowed, and for which role, is
 * written once in PrintingOrderStatus::transitions(). Every change is saved together with its history row.
 */
class StatusTransitionService
{
    /** Writes the first history row of a new order (nothing to nothing-before-it). Called by PrintingOrderService. */
    public function recordSubmission(PrintingOrder $order, User $actor): void
    {
        $this->writeHistory($order, null, PrintingOrderStatus::Submitted, $actor, null);
    }

    /**
     * Moves the order to a new status.
     *
     * The order row is locked and read again first, so two people acting at the same moment (for example the owner
     * accepting while the customer cancels) cannot both succeed from the same starting status.
     *
     * @throws AuthorizationException when a customer tries to touch an order that is not theirs
     * @throws InvalidStatusTransitionException when the move is not in the table, the role may not make it, or a required reason is missing
     */
    public function transition(PrintingOrder $order, PrintingOrderStatus $to, User $actor, ?string $note = null): PrintingOrder
    {
        $note = $note === null ? null : trim($note);
        $note = $note === '' ? null : $note;

        return DB::transaction(function () use ($order, $to, $actor, $note) {
            $current = PrintingOrder::query()->lockForUpdate()->findOrFail($order->getKey());
            $from = $current->status;

            if ($actor->role === UserRole::Customer && $current->customer_id !== $actor->id) {
                throw new AuthorizationException('This order belongs to another customer.');
            }

            if (! array_key_exists($to->value, $from->transitions())) {
                throw InvalidStatusTransitionException::notAllowed($from, $to);
            }

            if (! $from->canMoveTo($to, $actor->role)) {
                throw InvalidStatusTransitionException::notPermitted($from, $to);
            }

            if ($to === PrintingOrderStatus::Rejected && $note === null) {
                throw InvalidStatusTransitionException::noteRequired($to);
            }

            $current->status = $to;

            if ($to === PrintingOrderStatus::Rejected) {
                $current->rejection_reason = $note;
            }

            $current->save();
            $this->writeHistory($current, $from, $to, $actor, $note);

            // Hand the caller's own instance the saved values too
            $order->setRawAttributes($current->getAttributes(), true);

            return $order;
        });
    }

    private function writeHistory(PrintingOrder $order, ?PrintingOrderStatus $from, PrintingOrderStatus $to, User $actor, ?string $note): void
    {
        $history = new OrderStatusHistory;
        $history->forceFill([
            'printing_order_id' => $order->getKey(),
            'from_status' => $from,
            'to_status' => $to,
            'changed_by' => $actor->getKey(),
            'note' => $note,
        ])->save();
    }
}
