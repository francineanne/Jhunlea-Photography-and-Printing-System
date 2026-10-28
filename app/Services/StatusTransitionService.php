<?php

namespace App\Services;

use App\Enums\OrderEvent;
use App\Enums\PrintingOrderStatus;
use App\Enums\UserRole;
use App\Exceptions\InvalidStatusTransitionException;
use App\Models\OrderStatusHistory;
use App\Models\PrintingOrder;
use App\Models\User;
use App\Support\Money;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * The only code that changes the status of a printing order, and the other things the shop records on it: a request
 * for more information and the confirmed price. Which moves are allowed, and for which role, is written once in
 * PrintingOrderStatus::transitions(). Every change is saved together with its history row, on a row that is locked
 * and read again first, so a page that is out of date can never skip the rules.
 *
 * When the shop's request for information counts as answered is decided here only (see clearInfoRequest()):
 * the customer adds a file, or the order moves to another status.
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
     * Accepting needs the confirmed price, which is saved in the same transaction. Rejecting needs a reason.
     *
     * @throws AuthorizationException when a customer tries to touch an order that is not theirs
     * @throws InvalidStatusTransitionException when the move is not in the table, the role may not make it, or a required reason or price is missing
     */
    public function transition(PrintingOrder $order, PrintingOrderStatus $to, User $actor, ?string $note = null, ?string $confirmedPrice = null): PrintingOrder
    {
        $note = $this->clean($note);

        return $this->locked($order, $actor, function (PrintingOrder $current) use ($to, $actor, $note, $confirmedPrice) {
            $from = $current->status;

            if (! array_key_exists($to->value, $from->transitions())) {
                throw InvalidStatusTransitionException::notAllowed($from, $to);
            }

            if (! $from->canMoveTo($to, $actor->role)) {
                throw InvalidStatusTransitionException::notPermitted($from, $to);
            }

            if ($to === PrintingOrderStatus::Rejected && $note === null) {
                throw InvalidStatusTransitionException::noteRequired($to);
            }

            if ($to === PrintingOrderStatus::Accepted) {
                $current->confirmed_price = $this->price($confirmedPrice);
            }

            $current->status = $to;

            if ($to === PrintingOrderStatus::Rejected) {
                $current->rejection_reason = $note;
            }

            $this->clearInfoRequest($current);
            $current->save();
            $this->writeHistory($current, $from, $to, $actor, $note);
        });
    }

    /**
     * The shop asks the customer for more information, without changing the status. Sending the same request twice
     * (a double click) writes nothing the second time.
     *
     * @throws InvalidStatusTransitionException when the order is past review
     */
    public function requestInfo(PrintingOrder $order, User $owner, string $message): PrintingOrder
    {
        $message = (string) $this->clean($message);

        return $this->locked($order, $owner, function (PrintingOrder $current) use ($owner, $message) {
            $this->ownerOnly($owner);

            if (! $current->status->allowsInfoRequest()) {
                throw InvalidStatusTransitionException::infoNotAllowed($current->status);
            }

            if ($message === '') {
                throw InvalidStatusTransitionException::infoRequired();
            }

            if ($current->info_request === $message) {
                return;
            }

            $current->info_request = $message;
            $current->save();
            $this->writeHistory($current, $current->status, $current->status, $owner, $message, OrderEvent::InfoRequested);
        });
    }

    /**
     * Records that files were added to an order. When the customer adds them while the shop is waiting for more
     * information, that answers the request.
     */
    public function recordFilesAdded(PrintingOrder $order, User $actor, int $count): PrintingOrder
    {
        return $this->locked($order, $actor, function (PrintingOrder $current) use ($actor, $count) {
            if ($actor->role !== UserRole::Customer || ! $current->needsInfo()) {
                return;
            }

            $this->clearInfoRequest($current);
            $current->save();
            $this->writeHistory($current, $current->status, $current->status, $actor, $count === 1 ? 'Added 1 file.' : "Added {$count} files.", OrderEvent::InfoAnswered);
        });
    }

    /**
     * Changes the confirmed price after the order was accepted, until the prints are ready.
     *
     * @throws InvalidStatusTransitionException when the status does not allow it or the price is out of range
     */
    public function changePrice(PrintingOrder $order, User $owner, string $price, ?string $reason = null): PrintingOrder
    {
        $reason = $this->clean($reason);

        return $this->locked($order, $owner, function (PrintingOrder $current) use ($owner, $price, $reason) {
            $this->ownerOnly($owner);

            if (! $current->status->allowsPriceChange()) {
                throw InvalidStatusTransitionException::priceChangeNotAllowed($current->status);
            }

            $new = $this->price($price);
            $old = $current->confirmed_price;

            if ($old === $new) {
                return;
            }

            $current->confirmed_price = $new;
            $current->save();

            $note = 'Confirmed price changed from '.Money::format($old).' to '.Money::format($new).'.'.($reason ? ' '.$reason : '');
            $this->writeHistory($current, $current->status, $current->status, $owner, $note, OrderEvent::PriceChanged);
        });
    }

    /**
     * The one rule for when a request for information is answered: it is cleared here, and this is called only when
     * the order moves to another status or the customer adds a file.
     */
    private function clearInfoRequest(PrintingOrder $current): void
    {
        $current->info_request = null;
    }

    /**
     * Runs a change on the order row, locked and read again inside one transaction, then hands the caller's own
     * instance the saved values.
     *
     * @param  Closure(PrintingOrder): void  $change
     */
    private function locked(PrintingOrder $order, User $actor, Closure $change): PrintingOrder
    {
        return DB::transaction(function () use ($order, $actor, $change) {
            $current = PrintingOrder::query()->lockForUpdate()->findOrFail($order->getKey());

            if ($actor->role === UserRole::Customer && $current->customer_id !== $actor->id) {
                throw new AuthorizationException('This order belongs to another customer.');
            }

            $change($current);

            $order->setRawAttributes($current->getAttributes(), true);

            return $order;
        });
    }

    private function ownerOnly(User $actor): void
    {
        if ($actor->role !== UserRole::Owner) {
            throw new AuthorizationException('Only the shop can do this.');
        }
    }

    /** A price as the two-decimal string the database stores, checked against the money column's range. */
    private function price(?string $value): string
    {
        $value = $value === null ? '' : trim($value);

        if ($value === '') {
            throw InvalidStatusTransitionException::priceRequired();
        }

        if (! preg_match('/^\d{1,8}(\.\d{1,2})?$/', $value)) {
            throw InvalidStatusTransitionException::priceOutOfRange();
        }

        return Money::fromCents(Money::toCents($value));
    }

    private function clean(?string $text): ?string
    {
        $text = $text === null ? null : trim($text);

        return $text === '' ? null : $text;
    }

    private function writeHistory(PrintingOrder $order, ?PrintingOrderStatus $from, PrintingOrderStatus $to, User $actor, ?string $note, ?OrderEvent $event = null): void
    {
        $history = new OrderStatusHistory;
        $history->forceFill([
            'printing_order_id' => $order->getKey(),
            'from_status' => $from,
            'to_status' => $to,
            'event' => $event,
            'changed_by' => $actor->getKey(),
            'note' => $note,
        ])->save();
    }
}
