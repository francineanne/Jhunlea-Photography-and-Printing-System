<?php

namespace App\Support;

use App\Enums\BookingStatus;
use App\Enums\PrintingOrderStatus;
use App\Models\BookingSetting;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;

/**
 * Phase 10D: the short "what happens next" timeline a customer reads after sending a request and on its detail page.
 * It only WORDS what the real status already says (read only; nothing here decides anything). Each step is
 * ['label' => ..., 'text' => ..., 'state' => 'done' | 'current' | 'upcoming'].
 */
class NextSteps
{
    public function __construct(private readonly ShopSettings $settings) {}

    /**
     * @return array{steps: list<array{label: string, text: string, state: string}>, closed: ?string}
     */
    public function forBooking(PhotographyBooking $booking): array
    {
        $status = $booking->status;
        $paid = $booking->recordedPayments()->isNotEmpty();
        $needsDown = $status === BookingStatus::AwaitingDownpayment
            || (in_array($status, [BookingStatus::Requested, BookingStatus::Proposed], true) && $this->settings->downpaymentPercent() > 0);

        $hold = $booking->hold_expires_at !== null && $booking->hold_expires_at->isFuture()
            ? 'We hold your time until '.LocalTime::format($booking->hold_expires_at).'.'
            : 'We hold your time for '.BookingSetting::current()->request_hold_hours.' hours while you wait.';

        $steps = [
            ['label' => 'Request sent', 'text' => 'Sent on '.LocalTime::format($booking->created_at).'.'],
            ['label' => 'Studio reviews', 'text' => match ($status) {
                BookingStatus::Proposed => 'The studio offered another time. Accept it or ask for a different one.',
                BookingStatus::Requested => $hold,
                default => 'The studio confirmed your time.',
            }],
        ];

        if ($needsDown) {
            $steps[] = ['label' => 'Pay the downpayment', 'text' => $booking->downpayment_due_at !== null
                ? 'Due by '.LocalTime::format($booking->downpayment_due_at).' to keep your time.'
                : 'A '.$this->settings->downpaymentPercent().'% downpayment keeps your time once the studio confirms.'];
        }

        $steps[] = ['label' => 'Shoot day', 'text' => 'Come on '.LocalTime::format($booking->starts_at).'. '.($needsDown || $paid ? 'Pay the balance at the studio.' : 'Pay at the studio.')];
        $steps[] = ['label' => 'Photos released', 'text' => 'Released once the booking is fully paid. You can order prints after.'];

        $current = match ($status) {
            BookingStatus::Requested, BookingStatus::Proposed => 1,
            BookingStatus::AwaitingDownpayment => 2,
            BookingStatus::Confirmed => count($steps) - 2,
            BookingStatus::Completed => count($steps),
            default => 1,
        };

        $closed = match (true) {
            $status === BookingStatus::Rejected => 'The studio could not take this request. You can send a new one any time.',
            $status === BookingStatus::Cancelled => 'This booking was cancelled, so nothing more happens here.',
            $booking->isExpired() => 'This request was not confirmed before its time. You can send a new one.',
            default => null,
        };

        return ['steps' => $this->mark($steps, $current), 'closed' => $closed];
    }

    /**
     * @return array{steps: list<array{label: string, text: string, state: string}>, closed: ?string}
     */
    public function forOrder(PrintingOrder $order): array
    {
        $status = $order->status;
        $prepay = (bool) $order->requires_prepayment;

        $steps = [
            ['label' => 'Order sent', 'text' => 'Sent on '.LocalTime::format($order->created_at).'.'],
            ['label' => 'Shop reviews', 'text' => 'The shop checks your files and confirms the final price.'],
        ];

        if ($prepay) {
            $steps[] = ['label' => 'Pay first', 'text' => 'This order is paid in full before printing starts.'];
        }

        $steps[] = ['label' => 'Printing', 'text' => 'The shop prints your order.'];
        $steps[] = ['label' => 'Ready for pickup', 'text' => $prepay ? 'Pick it up at the shop.' : 'Pick it up and pay at the shop.'];
        $steps[] = ['label' => 'Completed', 'text' => 'Released once it is fully paid.'];

        $printing = $prepay ? 3 : 2;
        $current = match ($status) {
            PrintingOrderStatus::Submitted, PrintingOrderStatus::UnderReview => 1,
            PrintingOrderStatus::Accepted => 2,
            PrintingOrderStatus::Processing => $printing,
            PrintingOrderStatus::ReadyForPickup => $printing + 1,
            PrintingOrderStatus::Completed => count($steps),
            default => 1,
        };

        $closed = match ($status) {
            PrintingOrderStatus::Rejected => 'The shop could not take this order. You can send a new one any time.',
            PrintingOrderStatus::Cancelled => 'This order was cancelled, so nothing more happens here.',
            PrintingOrderStatus::Unclaimed => 'This order was closed because it was not picked up.',
            default => null,
        };

        return ['steps' => $this->mark($steps, $current), 'closed' => $closed];
    }

    /**
     * @param  list<array{label: string, text: string}>  $steps
     * @return list<array{label: string, text: string, state: string}>
     */
    private function mark(array $steps, int $current): array
    {
        return array_map(fn (array $step, int $index) => $step + ['state' => $index < $current ? 'done' : ($index === $current ? 'current' : 'upcoming')], $steps, array_keys($steps));
    }
}
