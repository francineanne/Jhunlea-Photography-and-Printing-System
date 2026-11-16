<?php

namespace App\Exceptions;

use App\Enums\PrintingOrderStatus;
use App\Support\LocalTime;
use DomainException;
use Illuminate\Support\Carbon;

/** Every message here can be shown to the person who tried the action. */
class InvalidStatusTransitionException extends DomainException
{
    public static function notAllowed(PrintingOrderStatus $from, PrintingOrderStatus $to): self
    {
        return new self("This order is {$from->label()}, so it cannot be moved to {$to->label()}. The page may be out of date; please reload it.");
    }

    public static function notPermitted(PrintingOrderStatus $from, PrintingOrderStatus $to): self
    {
        return new self("You are not allowed to move this order from {$from->label()} to {$to->label()}.");
    }

    public static function noteRequired(PrintingOrderStatus $to): self
    {
        return new self("A reason is required to mark this order {$to->label()}.");
    }

    public static function priceRequired(): self
    {
        return new self('Enter the confirmed price before accepting this order.');
    }

    public static function priceOutOfRange(): self
    {
        return new self('The confirmed price must be from ₱0.00 to ₱99,999,999.99.');
    }

    public static function infoNotAllowed(PrintingOrderStatus $status): self
    {
        return new self("More information can only be asked for while an order is Submitted or Under review. This order is {$status->label()}.");
    }

    public static function infoRequired(): self
    {
        return new self('Write what information you need from the customer.');
    }

    public static function priceChangeNotAllowed(PrintingOrderStatus $status): self
    {
        return new self("The confirmed price can only be changed while an order is Accepted or Processing. This order is {$status->label()}.");
    }

    public static function notPaid(): self
    {
        return new self(PaymentException::RELEASE_MESSAGE);
    }

    public static function prepaymentRequired(): self
    {
        return new self('Collect the payment before printing. This order is paid before it is printed.');
    }

    public static function customerCannotCancelPaid(): self
    {
        return new self('This order is already paid, so it cannot be cancelled here. Please message the shop.');
    }

    public static function unclaimedButPaid(): self
    {
        return new self('This order is paid, so it stays ready for pickup. Only an unpaid order can be marked unclaimed.');
    }

    public static function unclaimedTooSoon(int $days, ?Carbon $from): self
    {
        return new self("An order can be marked unclaimed {$days} ".($days === 1 ? 'day' : 'days').' after it became ready for pickup'
            .($from ? ' (from '.LocalTime::format($from).')' : '').'.');
    }

    public static function paymentChoiceRequired(): self
    {
        return new self('This order has a recorded payment. Choose "Keep the payment (not refunded)" or "Refund: void the payment".');
    }

    public static function refundReasonRequired(): self
    {
        return new self('Write the reason for the refund. It is kept with the voided payment.');
    }

    public static function priceLocked(): self
    {
        return new self(PaymentException::PRICE_LOCKED_MESSAGE);
    }
}
