<?php

namespace App\Enums;

use App\Support\Money;

/**
 * Where a printing order or a photography booking stands on payment. It is never stored or typed: derive() works it
 * out from the confirmed price and whether a recorded payment exists, and that is the only rule. The shop takes the
 * full confirmed price in one payment (no partial payments, no downpayments).
 */
enum PaymentStatus: string
{
    case AwaitingPrice = 'awaiting_price';
    case Unpaid = 'unpaid';
    case Paid = 'paid';
    case NoCharge = 'no_charge';

    /** The one rule. The amount due is always the confirmed price, never the estimate. */
    public static function derive(?string $confirmedPrice, bool $hasRecordedPayment): self
    {
        return match (true) {
            $hasRecordedPayment => self::Paid,
            $confirmedPrice === null => self::AwaitingPrice,
            Money::toCents($confirmedPrice) === 0 => self::NoCharge,
            default => self::Unpaid,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::AwaitingPrice => 'Awaiting price',
            self::Unpaid => 'Unpaid',
            self::Paid => 'Paid',
            self::NoCharge => 'No charge',
        };
    }

    /** The badge colour (DESIGN.md: paid green, unpaid red). */
    public function tone(): string
    {
        return match ($this) {
            self::AwaitingPrice => 'blue',
            self::Unpaid => 'red',
            self::Paid => 'green',
            self::NoCharge => 'gray',
        };
    }

    /** Nothing more is due: the order or the photos may be released. */
    public function isSettled(): bool
    {
        return in_array($this, [self::Paid, self::NoCharge], true);
    }
}
