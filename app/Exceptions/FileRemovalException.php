<?php

namespace App\Exceptions;

use App\Enums\PrintingOrderStatus;
use DomainException;

/** Why a customer's file could not be removed. Every message can be shown to the customer. */
class FileRemovalException extends DomainException
{
    public static function closed(PrintingOrderStatus $status): self
    {
        return new self("This order is {$status->label()}, so its files can no longer be changed. Please talk to the shop.");
    }

    public static function lastFile(): self
    {
        return new self('An order needs at least one file. Add the new file first, then remove the old one.');
    }

    public static function gone(): self
    {
        return new self('That file is no longer on this order. The page may be out of date.');
    }
}
