<?php

namespace App\Exceptions;

use App\Enums\PrintingOrderStatus;
use DomainException;

class InvalidStatusTransitionException extends DomainException
{
    public static function notAllowed(PrintingOrderStatus $from, PrintingOrderStatus $to): self
    {
        return new self("A printing order cannot move from {$from->value} to {$to->value}.");
    }

    public static function notPermitted(PrintingOrderStatus $from, PrintingOrderStatus $to): self
    {
        return new self("You are not allowed to move a printing order from {$from->value} to {$to->value}.");
    }

    public static function noteRequired(PrintingOrderStatus $to): self
    {
        return new self("A reason is required to move a printing order to {$to->value}.");
    }
}
