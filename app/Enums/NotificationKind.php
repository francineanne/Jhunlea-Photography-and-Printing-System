<?php

namespace App\Enums;

use App\Support\LocalTime;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Every notification the system sends (Phase 5B), who it is for, and the ONE message map: the sentence is built here
 * from the small stored data when it is shown, never stored, so the wording can change later. A new case without a
 * message fails (the match has no default) and NotificationKindTest catches it.
 *
 * Stored data: kind, payable_type (morph map alias), payable_id, reference (PR or PB), and where a kind needs them the
 * amount and the PAY reference. Never a GCash reference, a void or rejection reason, a note, a phone or an email. The
 * message kinds store only kind, conversation_id and sender_name: never the message text.
 */
enum NotificationKind: string
{
    // To the customer, when the shop does it
    case OrderAccepted = 'order_accepted';
    case OrderRejected = 'order_rejected';
    case OrderInfoRequested = 'order_info_requested';
    case OrderProcessing = 'order_processing';
    case OrderReady = 'order_ready';
    case OrderCompleted = 'order_completed';
    case OrderPriceChanged = 'order_price_changed';
    case BookingConfirmed = 'booking_confirmed';
    case BookingProposed = 'booking_proposed';
    case BookingRejected = 'booking_rejected';
    case BookingPriceChanged = 'booking_price_changed';
    case BookingCompleted = 'booking_completed';
    case PaymentRecorded = 'payment_recorded';
    case PaymentVoided = 'payment_voided';
    case OrderCancelledByShop = 'order_cancelled_by_shop';
    case BookingCancelledByShop = 'booking_cancelled_by_shop';
    // Phase 10B: downpayments, balances, prepaid printing, unclaimed orders (customer audience)
    case DownpaymentRequested = 'downpayment_requested';
    case DownpaymentRecorded = 'downpayment_recorded';
    case BalanceRecorded = 'balance_recorded';
    case DeadlineExtended = 'deadline_extended';
    case DownpaymentWaived = 'downpayment_waived';
    case DownpaymentExpired = 'downpayment_expired';
    case OrderPrepayRequired = 'order_prepay_required';
    case OrderUnclaimed = 'order_unclaimed';

    // To the owner, when a customer does it
    case OrderPlaced = 'order_placed';
    case BookingRequested = 'booking_requested';
    case OrderCancelledByCustomer = 'order_cancelled_by_customer';
    case BookingCancelledByCustomer = 'booking_cancelled_by_customer';
    case ProposalAccepted = 'proposal_accepted';
    case AnotherTimeRequested = 'another_time_requested';
    case InfoAnswered = 'info_answered';

    // Studio chat (Phase 7B): only the conversation id and the sender's name are stored, never the message text
    case MessageReceived = 'message_received';
    case MessageReplied = 'message_replied';

    /** Who receives it. Nobody is told about their own action: the other side always made it. */
    public function audience(): UserRole
    {
        return match ($this) {
            self::OrderPlaced, self::BookingRequested, self::OrderCancelledByCustomer, self::BookingCancelledByCustomer,
            self::ProposalAccepted, self::AnotherTimeRequested, self::InfoAnswered, self::MessageReceived => UserRole::Owner,
            default => UserRole::Customer,
        };
    }

    /** The headline, a few words. */
    public function title(): string
    {
        return match ($this) {
            self::OrderAccepted => 'Order accepted',
            self::OrderRejected => 'Order rejected',
            self::OrderInfoRequested => 'More information needed',
            self::OrderProcessing => 'Order being printed',
            self::OrderReady => 'Ready for pickup',
            self::OrderCompleted => 'Order completed',
            self::OrderPriceChanged, self::BookingPriceChanged => 'Confirmed price changed',
            self::BookingConfirmed => 'Photoshoot confirmed',
            self::BookingProposed => 'New time proposed',
            self::BookingRejected => 'Request rejected',
            self::BookingCompleted => 'Photoshoot completed',
            self::PaymentRecorded => 'Payment recorded',
            self::PaymentVoided => 'Payment record cancelled',
            self::OrderCancelledByShop => 'Order cancelled by the shop',
            self::BookingCancelledByShop => 'Booking cancelled by the studio',
            self::OrderPlaced => 'New printing order',
            self::BookingRequested => 'New photoshoot request',
            self::OrderCancelledByCustomer => 'Order cancelled by the customer',
            self::BookingCancelledByCustomer => 'Booking cancelled by the customer',
            self::ProposalAccepted => 'Proposed time accepted',
            self::AnotherTimeRequested => 'Another time requested',
            self::InfoAnswered => 'Customer added files',
            self::MessageReceived => 'New message',
            self::MessageReplied => 'The studio replied',
            self::DownpaymentRequested => 'Downpayment needed',
            self::DownpaymentRecorded => 'Downpayment recorded',
            self::BalanceRecorded => 'Balance recorded',
            self::DeadlineExtended => 'Deadline extended',
            self::DownpaymentWaived => 'Downpayment waived',
            self::DownpaymentExpired => 'Booking cancelled: no downpayment',
            self::OrderPrepayRequired => 'Pay before printing',
            self::OrderUnclaimed => 'Order closed as unclaimed',
        };
    }

    /**
     * The sentence people read, from the stored data.
     *
     * @param  array<string, mixed>  $data
     */
    public function message(array $data): string
    {
        $ref = (string) ($data['reference'] ?? '');
        $amount = isset($data['amount']) ? Money::format((string) $data['amount']) : null;
        $pay = (string) ($data['payment'] ?? '');
        $place = ($data['payable_type'] ?? '') === 'photography_booking' ? 'studio' : 'shop';
        $due = isset($data['due']) ? LocalTime::format(Carbon::parse((string) $data['due'], 'UTC')) : null;

        return match ($this) {
            self::OrderAccepted => "Your order {$ref} was accepted".($amount ? " at {$amount}" : '').'. Open it to see when to pay and how.',
            self::OrderRejected => "Your order {$ref} was rejected. Open it to read the shop’s reason.",
            self::OrderInfoRequested => "The shop needs more information about your order {$ref}. Open it to see what is needed.",
            self::OrderProcessing => "Your order {$ref} is being printed.",
            self::OrderReady => "Your order {$ref} is ready for pickup. Pay the full amount at the shop when you collect it.",
            self::OrderCompleted => "Your order {$ref} is completed. Thank you!",
            self::OrderPriceChanged, self::BookingPriceChanged => "The confirmed price of {$ref} is now {$amount}.",
            self::BookingConfirmed => "Your photoshoot {$ref} is confirmed. Open it for the date and time.",
            self::BookingProposed => "The studio proposed a new time for {$ref}. Open it to accept it or ask for another time.",
            self::BookingRejected => "Your photoshoot request {$ref} was rejected. Open it to read the studio’s reason.",
            self::BookingCompleted => "Your photoshoot {$ref} is completed. Thank you!",
            self::PaymentRecorded => "Payment {$pay} of {$amount} was recorded for {$ref}. This is a payment record, not an official receipt.",
            self::PaymentVoided => "The {$place} cancelled payment record {$pay} for {$ref}. Please contact the {$place}.",
            // No reason or note here: the customer reads it on the order or booking
            self::OrderCancelledByShop => "The shop cancelled your order {$ref}. Open it to see the details.",
            self::BookingCancelledByShop => "The studio cancelled your booking {$ref}. Open it to see the details.",
            self::OrderPlaced => "A customer sent a new printing order, {$ref}.",
            self::BookingRequested => "A customer requested a photoshoot, {$ref}.",
            self::OrderCancelledByCustomer => "The customer cancelled order {$ref}.",
            self::BookingCancelledByCustomer => "The customer cancelled booking {$ref}.",
            self::ProposalAccepted => "The customer accepted the proposed time for {$ref}.".($due ? " It now waits for the downpayment (due {$due})." : ' It is now confirmed.'),
            self::AnotherTimeRequested => "The customer asked for another time for {$ref}.",
            self::InfoAnswered => "The customer added files to {$ref} in reply to your request.",
            self::MessageReceived => 'New message from '.((string) ($data['sender_name'] ?? '') ?: 'a customer').'.',
            self::MessageReplied => 'The studio replied to your message.',
            self::DownpaymentRequested => "The studio confirmed {$ref}. Pay the downpayment".($amount ? " of {$amount}" : '').($due ? " by {$due}" : '').' to keep your time. Open it to see how to pay.',
            self::DownpaymentRecorded => "Downpayment {$pay} of {$amount} was recorded for {$ref}. Your photoshoot is confirmed; the balance is paid on the day of the shoot.",
            self::BalanceRecorded => "Balance {$pay} of {$amount} was recorded for {$ref}. Your photoshoot is fully paid.",
            self::DeadlineExtended => "The studio gave you more time to pay the downpayment of {$ref}".($due ? ": now due by {$due}" : '').'.',
            self::DownpaymentWaived => "The studio waived the downpayment of {$ref}. Your photoshoot is confirmed; the full price is paid on the day of the shoot.",
            self::DownpaymentExpired => "Your booking {$ref} was cancelled because the downpayment was not paid by the deadline. You can send a new request.",
            self::OrderPrepayRequired => "Your order {$ref} was accepted".($amount ? " at {$amount}" : '').'. Please pay the full amount before we print it. Open it to see how to pay.',
            self::OrderUnclaimed => "Your order {$ref} was not picked up or paid, so the shop closed it as unclaimed. Your next orders are paid before printing.",
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::OrderAccepted, self::BookingConfirmed, self::ProposalAccepted => 'check_circle',
            self::OrderRejected, self::BookingRejected, self::OrderCancelledByCustomer, self::BookingCancelledByCustomer => 'block',
            self::OrderCancelledByShop, self::BookingCancelledByShop => 'cancel',
            self::OrderInfoRequested, self::InfoAnswered => 'help',
            self::OrderProcessing => 'print',
            self::OrderReady => 'inventory_2',
            self::OrderCompleted, self::BookingCompleted => 'task_alt',
            self::OrderPriceChanged, self::BookingPriceChanged => 'sell',
            self::BookingProposed, self::AnotherTimeRequested => 'schedule',
            self::PaymentRecorded, self::PaymentVoided => 'point_of_sale',
            self::OrderPlaced => 'receipt_long',
            self::BookingRequested => 'photo_camera',
            self::MessageReceived, self::MessageReplied => 'chat',
            self::DownpaymentRequested, self::OrderPrepayRequired, self::DeadlineExtended => 'payments',
            self::DownpaymentRecorded, self::BalanceRecorded, self::DownpaymentWaived => 'point_of_sale',
            self::DownpaymentExpired, self::OrderUnclaimed => 'cancel',
        };
    }

    /** A studio chat notice: it points to a conversation, not to an order or booking. */
    public function isMessage(): bool
    {
        return $this === self::MessageReceived || $this === self::MessageReplied;
    }

    /** Where on the target page to land. */
    public function anchor(): string
    {
        return match ($this) {
            self::PaymentRecorded, self::PaymentVoided, self::OrderPriceChanged, self::BookingPriceChanged,
            self::DownpaymentRequested, self::DownpaymentRecorded, self::BalanceRecorded, self::DeadlineExtended, self::DownpaymentWaived, self::OrderPrepayRequired => '#payment',
            default => '',
        };
    }
}
