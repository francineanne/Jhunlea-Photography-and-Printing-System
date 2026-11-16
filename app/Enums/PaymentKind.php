<?php

namespace App\Enums;

/**
 * What a payment pays for (Phase 10B). A printing order is paid with one `full` payment. A photoshoot is paid either
 * with one `full` payment, or with a `downpayment` (to hold the time) and later its `balance`. PaymentService decides
 * which kinds may be recorded next, on the locked order or booking row; the database keeps one active payment of each
 * kind per order or booking.
 */
enum PaymentKind: string
{
    case Full = 'full';
    case Downpayment = 'downpayment';
    case Balance = 'balance';

    public function label(): string
    {
        return match ($this) {
            self::Full => 'Full payment',
            self::Downpayment => 'Downpayment',
            self::Balance => 'Balance',
        };
    }

    /** The short word on a ledger row, slip or button: "Full", "Downpayment", "Balance". */
    public function short(): string
    {
        return match ($this) {
            self::Full => 'Full',
            self::Downpayment => 'Downpayment',
            self::Balance => 'Balance',
        };
    }
}
