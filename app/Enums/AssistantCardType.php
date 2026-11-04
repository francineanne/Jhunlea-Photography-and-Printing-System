<?php

namespace App\Enums;

/**
 * The records the assistant may point to with a card. Cards are always built from the database, never by the model:
 * catalog records (service, package, theme) only when published, and (Phase 7E-1) the signed-in customer's own
 * printing orders and photography bookings, only for that customer.
 */
enum AssistantCardType: string
{
    case Service = 'service';
    case Package = 'package';
    case Theme = 'theme';
    case Order = 'order';
    case Booking = 'booking';

    /** One of the customer's own records rather than the public catalog. */
    public function isRecord(): bool
    {
        return in_array($this, [self::Order, self::Booking], true);
    }

    /** The morph map alias of a record type (chat message "about" references use it). */
    public function morphAlias(): ?string
    {
        return match ($this) {
            self::Order => 'printing_order',
            self::Booking => 'photography_booking',
            default => null,
        };
    }
}
