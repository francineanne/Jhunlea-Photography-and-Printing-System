<?php

namespace App\Exceptions;

use DomainException;

/**
 * Why a payment could not be recorded or voided. Every message can be shown to the owner. The two messages the status
 * services also show (the release gate and the price lock) live here too, so each rule has one wording.
 */
class PaymentException extends DomainException
{
    public const RELEASE_MESSAGE = 'Record the payment first. Photos and orders are released only when fully paid.';

    public const PRICE_LOCKED_MESSAGE = 'Void the payment first. The confirmed price cannot change while a payment is recorded.';

    public static function closed(string $what, string $status): self
    {
        return new self("This {$what} is {$status}, so a payment cannot be recorded for it.");
    }

    public static function noPrice(): self
    {
        return new self('There is no confirmed price yet. Confirm the price first; the payment is always the confirmed price.');
    }

    public static function bookingNotConfirmed(): self
    {
        return new self('A photoshoot payment can be recorded once the booking is confirmed.');
    }

    public static function noCharge(): self
    {
        return new self('The confirmed price is ₱0.00, so there is nothing to pay.');
    }

    public static function alreadyPaid(): self
    {
        return new self('A payment is already recorded for this. Nothing new was saved.');
    }

    public static function priceChanged(string $now): self
    {
        return new self("The confirmed price changed to {$now} after this page was opened. Nothing was saved; check the amount and record the payment again.");
    }

    public static function gcashReferenceRequired(): self
    {
        return new self('Enter the GCash reference number from the customer’s receipt.');
    }

    public static function gcashReferenceFormat(): self
    {
        return new self('The GCash reference must be 6 to 40 letters, digits or hyphens.');
    }

    public static function gcashReferenceTaken(): self
    {
        return new self('This GCash reference already belongs to another recorded payment. Check the number on the customer’s receipt.');
    }

    public static function voidReasonRequired(): self
    {
        return new self('Write why this payment record is being cancelled (up to 500 characters).');
    }

    public static function notRecorded(): self
    {
        return new self('This payment record was already cancelled. Nothing was changed.');
    }

    public static function released(string $what): self
    {
        return new self("This {$what} is Completed, so it was released and its payment is final. It cannot be voided.");
    }

    public static function wrongPayable(): self
    {
        return new self('This payment does not belong here. Reload the page and try again.');
    }
}
