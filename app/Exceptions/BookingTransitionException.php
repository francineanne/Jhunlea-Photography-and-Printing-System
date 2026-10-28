<?php

namespace App\Exceptions;

use App\Enums\BookingStatus;
use DomainException;

/** Why a booking could not move or change. Every message can be shown to the person who tried. */
class BookingTransitionException extends DomainException
{
    // Codes for the two refusals a page may want to word for its own reader (the customer accepting a proposed time
    // reads a customer message instead of the studio's); the messages themselves stay as they are
    public const TIME_TAKEN = 1;

    public const TIME_NOT_OPEN = 2;

    public static function notAllowed(BookingStatus $from, BookingStatus $to): self
    {
        return new self("This booking is {$from->label()}, so it cannot be moved to {$to->label()}. The page may be out of date; please reload it.");
    }

    public static function notPermitted(BookingStatus $from, BookingStatus $to): self
    {
        return new self("You are not allowed to move this booking from {$from->label()} to {$to->label()}.");
    }

    public static function reasonRequired(): self
    {
        return new self('Write the reason. The customer will see it.');
    }

    public static function noteRequired(): self
    {
        return new self('Write a short note for the customer.');
    }

    public static function priceRequired(): self
    {
        return new self('This package is priced on request. Enter the price before confirming.');
    }

    public static function priceOutOfRange(): self
    {
        return new self('The price must be from ₱0.00 to ₱99,999,999.99.');
    }

    public static function priceChangeNotAllowed(BookingStatus $status): self
    {
        return new self("The confirmed price can only be changed while a booking is Confirmed. This booking is {$status->label()}.");
    }

    public static function conflict(): self
    {
        return new self('This time is no longer free: another confirmed session is booked then. Propose another time instead.', self::TIME_TAKEN);
    }

    public static function timePassed(): self
    {
        return new self('The time of this request has already passed, so it cannot be confirmed. Reject or cancel it, or offer another time.');
    }

    /** @param  list<string>  $reasons */
    public static function outsideSchedule(array $reasons): self
    {
        return new self('This time is outside the usual schedule: '.implode(' ', $reasons).' Tick “Confirm anyway” to confirm it all the same.');
    }

    public static function slotNotAvailable(): self
    {
        return new self('That time is not open for bookings. Pick a free time within opening hours.', self::TIME_NOT_OPEN);
    }

    public static function noProposal(): self
    {
        return new self('There is no proposed time to accept.');
    }

    public static function tooLateToCancel(int $hours): self
    {
        return new self("A confirmed booking can be cancelled here until {$hours} hours before it starts. Please talk to the studio.");
    }

    public static function internalNoteTooLong(int $max): self
    {
        return new self("Keep the internal note to {$max} characters.");
    }

    public static function notStartedYet(): self
    {
        return new self('A photoshoot can be marked completed only after its start time.');
    }
}
