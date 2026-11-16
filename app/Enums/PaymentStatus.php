<?php

namespace App\Enums;

use App\Support\Money;

/**
 * Where a printing order or a photography booking stands on payment. It is never stored or typed: derive() works it
 * out from the confirmed price and what the RECORDED (active) payments add up to, and that is the only rule.
 * Since Phase 10B a photoshoot may be paid in two steps: a downpayment (to hold the time) and the balance on the day.
 */
enum PaymentStatus: string
{
    case AwaitingPrice = 'awaiting_price';
    case Unpaid = 'unpaid';
    case DownpaymentPaid = 'downpayment_paid';
    case Paid = 'paid';
    case NoCharge = 'no_charge';

    /**
     * The one rule. The amount due is always the confirmed price, never the estimate.
     *
     * @param  int|bool  $paid  the active payments in whole centavos; `true` means paid in full (a printing order's one
     *                          payment), `false` nothing recorded
     */
    public static function derive(?string $confirmedPrice, int|bool $paid): self
    {
        if ($paid === true) {
            return self::Paid;
        }

        $paidCents = $paid === false ? 0 : $paid;

        return match (true) {
            $confirmedPrice === null => $paidCents > 0 ? self::Paid : self::AwaitingPrice,
            Money::toCents($confirmedPrice) === 0 => $paidCents > 0 ? self::Paid : self::NoCharge,
            $paidCents >= Money::toCents($confirmedPrice) => self::Paid,
            $paidCents > 0 => self::DownpaymentPaid,
            default => self::Unpaid,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::AwaitingPrice => 'Awaiting price',
            self::Unpaid => 'Unpaid',
            self::DownpaymentPaid => 'Downpayment paid',
            self::Paid => 'Paid',
            self::NoCharge => 'No charge',
        };
    }

    /** The badge colour (DESIGN.md: paid green, unpaid red; a downpayment with its balance still due is amber). */
    public function tone(): string
    {
        return match ($this) {
            self::AwaitingPrice => 'blue',
            self::Unpaid => 'red',
            self::DownpaymentPaid => 'amber',
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
