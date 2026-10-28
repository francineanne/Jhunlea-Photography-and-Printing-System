<?php

namespace App\Exceptions;

use App\Enums\PrintingOrderStatus;
use DomainException;

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
}
