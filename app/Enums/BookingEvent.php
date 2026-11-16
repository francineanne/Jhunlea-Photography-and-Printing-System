<?php

namespace App\Enums;

/**
 * What kind of step a booking history row records. Null for a plain status change. The proposal steps also change the
 * status; their event says why, so a timeline can tell "the studio proposed a time" from any other move.
 */
enum BookingEvent: string
{
    case ProposalSent = 'proposal_sent';
    case ProposalAccepted = 'proposal_accepted';
    case TimeRequestedAgain = 'time_requested_again';
    case PriceChanged = 'price_changed';
    case FilesAdded = 'files_added';
    case PaymentRecorded = 'payment_recorded';
    case PaymentVoided = 'payment_voided';
    // Phase 10B (a status move with these events says why it happened)
    case ConfirmedByPayment = 'confirmed_by_payment';
    case BackToDownpayment = 'back_to_downpayment';
    case DownpaymentWaived = 'downpayment_waived';
    case DeadlineExtended = 'deadline_extended';
    case DownpaymentExpired = 'downpayment_expired';

    public function label(): string
    {
        return match ($this) {
            self::ProposalSent => 'New time proposed',
            self::ProposalAccepted => 'Proposed time accepted',
            self::TimeRequestedAgain => 'Another time requested',
            self::PriceChanged => 'Confirmed price changed',
            self::FilesAdded => 'Reference images added',
            self::PaymentRecorded => 'Payment recorded',
            self::PaymentVoided => 'Payment record cancelled by the shop',
            self::ConfirmedByPayment => 'Confirmed: payment received',
            self::BackToDownpayment => 'Awaiting downpayment again',
            self::DownpaymentWaived => 'Downpayment waived by the studio',
            self::DeadlineExtended => 'Downpayment deadline extended',
            self::DownpaymentExpired => 'Cancelled: downpayment not paid in time',
        };
    }
}
