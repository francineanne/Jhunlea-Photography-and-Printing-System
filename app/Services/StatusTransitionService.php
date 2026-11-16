<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\OrderEvent;
use App\Enums\PaymentStatus;
use App\Enums\PrintingOrderStatus;
use App\Enums\UserRole;
use App\Exceptions\InvalidStatusTransitionException;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\PrintingOrder;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Money;
use App\Support\PaymentRules;
use App\Support\ShopSettings;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
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
    public function __construct(private readonly NotificationDispatcher $notifications) {}

    /** Writes the first history row of a new order (nothing to nothing-before-it). Called by PrintingOrderService. */
    public function recordSubmission(PrintingOrder $order, User $actor): void
    {
        $this->writeHistory($order, null, PrintingOrderStatus::Submitted, $actor, null);
    }

    /**
     * The first history row of an order the owner made at the counter (Phase 8A): it starts Accepted, with its
     * confirmed price, so the timeline says who made it and when. Called by WalkInOrderService only.
     */
    public function recordCounterOrder(PrintingOrder $order, User $owner, string $note): void
    {
        $this->writeHistory($order, null, PrintingOrderStatus::Accepted, $owner, $note);
        app(AuditLogger::class)->record(AuditAction::OrderWalkInCreated, $order, ['price_cents' => AuditLogger::cents($order->confirmed_price), 'customer' => $order->customer?->name], actor: $owner);
    }

    /**
     * Moves the order to a new status.
     *
     * Accepting needs the confirmed price, which is saved in the same transaction. Rejecting needs a reason.
     *
     * Phase 10B, in $options:
     *  - 'prepay' (?bool, on Accepted): whether the order is paid before printing. It is ticked by itself from the
     *    owner's "Pay first from" amount or when the customer must pay first (users.prepay_required); the owner may tick
     *    it for any order or untick an amount-based one, never for a customer who must pay first. Null = the automatic
     *    answer. Accepted -> Processing is then refused until the order is paid.
     *  - 'payment_choice' and 'refund_reason' (owner cancel of an order with a recorded payment): KEEP_PAYMENT or
     *    REFUND_PAYMENT, as for bookings; a refund voids the payment in this transaction.
     * A customer may cancel an Accepted order only while nothing is paid (they saw the price and back out). Unclaimed
     * (owner) closes an unpaid order that waited at least "Unclaimed after days" since it became ready, and the customer
     * must pay first from then on.
     *
     * @throws AuthorizationException when a customer tries to touch an order that is not theirs
     * @throws InvalidStatusTransitionException when the move is not in the table, the role may not make it, or a required reason or price is missing
     */
    public const KEEP_PAYMENT = 'keep';

    public const REFUND_PAYMENT = 'refund';

    /** @param  array{prepay?: ?bool, payment_choice?: ?string, refund_reason?: ?string}  $options */
    public function transition(PrintingOrder $order, PrintingOrderStatus $to, User $actor, ?string $note = null, ?string $confirmedPrice = null, array $options = []): PrintingOrder
    {
        $note = $this->clean($note);

        return $this->locked($order, $actor, function (PrintingOrder $current) use ($to, $actor, $note, $confirmedPrice, $options) {
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

            // The release gate: an order is handed over (Completed) only when it is fully paid, or costs nothing
            $settled = fn () => PaymentStatus::derive($current->confirmed_price, $this->paidCents($current))->isSettled();

            if ($to === PrintingOrderStatus::Completed && ! $settled()) {
                throw InvalidStatusTransitionException::notPaid();
            }

            // Phase 10B: an order paid before printing is printed only once it is paid
            if ($to === PrintingOrderStatus::Processing && $current->requires_prepayment && ! $settled()) {
                throw InvalidStatusTransitionException::prepaymentRequired();
            }

            $paid = Payment::recordedFor($current)->exists();

            if ($to === PrintingOrderStatus::Cancelled && $actor->role === UserRole::Customer && $paid) {
                throw InvalidStatusTransitionException::customerCannotCancelPaid();
            }

            if ($to === PrintingOrderStatus::Unclaimed) {
                $this->checkUnclaimed($current, $paid);
            }

            $choice = $options['payment_choice'] ?? null;
            $refundReason = $this->clean($options['refund_reason'] ?? null);
            $refund = $to === PrintingOrderStatus::Cancelled && $actor->role === UserRole::Owner && $paid;

            if ($refund && ! in_array($choice, [self::KEEP_PAYMENT, self::REFUND_PAYMENT], true)) {
                throw InvalidStatusTransitionException::paymentChoiceRequired();
            }

            if ($refund && $choice === self::REFUND_PAYMENT && $refundReason === null) {
                throw InvalidStatusTransitionException::refundReasonRequired();
            }

            if ($to === PrintingOrderStatus::Accepted) {
                $current->confirmed_price = $this->price($confirmedPrice);
                $current->requires_prepayment = $this->prepay($current, $options['prepay'] ?? null);
            }

            if ($refund) {
                $note = trim(($note ?? '').' '.($choice === self::REFUND_PAYMENT ? 'The payment was refunded.' : 'The payment is kept (not refunded).'));
            }

            $current->status = $to;

            if ($to === PrintingOrderStatus::Rejected) {
                $current->rejection_reason = $note;
            }

            $this->clearInfoRequest($current);
            $current->save();
            $this->writeHistory($current, $from, $to, $actor, $note);

            if ($refund && $choice === self::REFUND_PAYMENT) {
                app(PaymentService::class)->refundAll($current, $actor, $refundReason);
            }

            // Unclaimed: the customer pays before printing from now on (walk-ins too), in this transaction
            if ($to === PrintingOrderStatus::Unclaimed) {
                app(PrepayFlagService::class)->set($current, $actor);
            }
        });
    }

    /**
     * Whether an order accepted now is paid before printing: by the owner's "Pay first from" amount, or because the
     * customer must pay first; the owner's tick wins, except that a flagged customer always pays first.
     */
    private function prepay(PrintingOrder $current, ?bool $ticked): bool
    {
        $flagged = (bool) User::query()->whereKey($current->customer_id)->value('prepay_required');

        if ($flagged) {
            return true;
        }

        return $ticked ?? app(PaymentRules::class)->prepayByPrice($current->confirmed_price);
    }

    /**
     * Unclaimed only for an order never paid that waited at least the owner's "Unclaimed after days" since it became
     * ready for pickup (the newest history row that moved it there). A paid order stays ready for pickup.
     */
    private function checkUnclaimed(PrintingOrder $current, bool $paid): void
    {
        if ($paid) {
            throw InvalidStatusTransitionException::unclaimedButPaid();
        }

        $days = app(ShopSettings::class)->paymentRule('unclaimed_after_days');
        $readyAt = self::readyAt($current);

        if ($readyAt === null || $readyAt->copy()->addDays($days)->gt(Carbon::now('UTC'))) {
            throw InvalidStatusTransitionException::unclaimedTooSoon($days, $readyAt?->copy()->addDays($days));
        }
    }

    /** When the order last became ready for pickup (UTC), from its history; null when it never did. */
    public static function readyAt(PrintingOrder $order): ?Carbon
    {
        $at = OrderStatusHistory::query()->where('printing_order_id', $order->getKey())
            ->where('to_status', PrintingOrderStatus::ReadyForPickup)->whereNull('event')->max('created_at');

        return $at === null ? null : Carbon::parse($at, 'UTC');
    }

    /** What the recorded payments add up to, in centavos (read inside the locked transaction). */
    private function paidCents(PrintingOrder $current): int
    {
        return (int) Payment::recordedFor($current)->get(['amount'])->sum(fn (Payment $payment) => Money::toCents($payment->amount));
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
     * Records that the customer removed one of their files. It does not answer a request for information: only
     * adding a file does (see clearInfoRequest()). Called by PrintingOrderService inside its own locked transaction.
     */
    public function recordFileRemoved(PrintingOrder $order, User $actor, string $fileName): void
    {
        $this->writeHistory($order, $order->status, $order->status, $actor, 'Removed '.$fileName, OrderEvent::FileRemoved);
    }

    /**
     * Writes the history row of a payment recorded or voided on the order. Called by PaymentService inside its own
     * locked transaction; the status does not change.
     */
    /** @param  array{payment?: string, amount?: string}  $context  for the customer's notification (never the GCash reference) */
    public function recordPaymentEvent(PrintingOrder $order, User $owner, OrderEvent $event, ?string $note, array $context = []): void
    {
        $this->writeHistory($order, $order->status, $order->status, $owner, $note, $event, $context);
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

            if (Payment::recordedFor($current)->exists()) {
                throw InvalidStatusTransitionException::priceLocked();
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
            app(AuditLogger::class)->record(AuditAction::OrderPriceChanged, $current, ['old_cents' => AuditLogger::cents($old), 'new_cents' => AuditLogger::cents($new), 'reason' => $reason], actor: $owner);
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

        return Money::normalizePrice($value) ?? throw InvalidStatusTransitionException::priceOutOfRange();
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
    private function writeHistory(PrintingOrder $order, ?PrintingOrderStatus $from, PrintingOrderStatus $to, ?User $actor, ?string $note, ?OrderEvent $event = null, array $context = []): void
    {
        $history = new OrderStatusHistory;
        $history->forceFill([
            'printing_order_id' => $order->getKey(),
            'from_status' => $from,
            'to_status' => $to,
            'event' => $event,
            'changed_by' => $actor?->getKey(),
            'note' => $note,
        ])->save();

        // Audit trail (8E): every real status move, in the action's transaction (payments and prices log themselves)
        if ($from !== null && $from !== $to && $event === null) {
            app(AuditLogger::class)->record(match ($to) {
                PrintingOrderStatus::Cancelled => AuditAction::OrderCancelled,
                PrintingOrderStatus::Rejected => AuditAction::OrderRejected,
                PrintingOrderStatus::Completed => AuditAction::OrderCompleted,
                PrintingOrderStatus::Unclaimed => AuditAction::OrderUnclaimed,
                default => AuditAction::OrderStatusChanged,
            }, $order, array_filter(['from' => $from->value, 'to' => $to->value,
                'price_cents' => $to === PrintingOrderStatus::Accepted ? AuditLogger::cents($order->confirmed_price) : null,
                'reason' => in_array($to, [PrintingOrderStatus::Cancelled, PrintingOrderStatus::Rejected], true) ? $note : null,
            ], fn ($value) => $value !== null), actor: $actor);
        }

        $this->notifications->orderHistory($order, $from, $to, $event, $actor, $context);
    }
}
