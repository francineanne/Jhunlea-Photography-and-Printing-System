<?php

namespace App\Support;

use App\Enums\BookingStatus;
use App\Enums\PrintingOrderStatus;
use App\Models\BookingSetting;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use Illuminate\Support\Carbon;

/**
 * Phase 10D: the short "what happens next" timeline a customer reads after sending a request and on its detail page.
 * It only WORDS what the real status already says (read only; nothing here decides anything). Each step is
 * ['label' => ..., 'text' => ..., 'state' => 'done' | 'current' | 'upcoming'].
 */
class NextSteps
{
    public function __construct(private readonly ShopSettings $settings) {}

    /**
     * Phase 10O: the ONE urgent sentence a customer reads about their own order or booking (the amber notice at the top
     * of its page, and the current step of the timeline): what to do, by when, and what happens if not. Words only, from
     * the real status, the deadline and the payment rules; null when nothing is urgent.
     *
     * @return array{text: string, until: ?Carbon}|null
     */
    public function warning(PrintingOrder|PhotographyBooking $record): ?array
    {
        if ($record instanceof PhotographyBooking) {
            if ($record->isExpired()) {
                return null;
            }

            return match ($record->status) {
                BookingStatus::AwaitingDownpayment => $record->downpayment_due_at === null || $record->confirmed_price === null ? null : [
                    'text' => 'Pay the downpayment of '.Money::cents($this->downpaymentDueCents($record)).' by '.LocalTime::format($record->downpayment_due_at)
                        .'. If it is not paid by then, this booking is cancelled automatically and the time is given to other customers.',
                    'until' => $record->downpayment_due_at,
                ],
                BookingStatus::Requested => [
                    'text' => 'If the studio does not answer before '.LocalTime::format($record->starts_at).', this request expires.',
                    'until' => null,
                ],
                BookingStatus::Proposed => $record->proposed_starts_at === null ? null : [
                    'text' => 'Accept or ask for another time before '.LocalTime::format($record->proposed_starts_at).', or this offer expires.',
                    'until' => null,
                ],
                default => null,
            };
        }

        $due = $record->amountDue();

        if ($record->status !== PrintingOrderStatus::ReadyForPickup || $due === null || Money::toCents($due) <= 0) {
            return null;
        }

        $days = (int) $this->settings->paymentRule('unclaimed_after_days');
        $ready = $record->relationLoaded('statusHistories')
            ? $record->statusHistories->where('to_status', PrintingOrderStatus::ReadyForPickup)->sortByDesc('id')->first()
            : $record->statusHistories()->where('to_status', PrintingOrderStatus::ReadyForPickup)->reorder('id', 'desc')->first();

        return [
            'text' => 'Please pick up and pay within '.$days.' '.($days === 1 ? 'day' : 'days').' of '.LocalTime::format($ready?->created_at ?? $record->updated_at, 'M j, Y')
                .'. After that the shop may close it as Unclaimed, and your next orders must be paid first.',
            'until' => null,
        ];
    }

    /** The downpayment still to pay on a booking awaiting it (the rule's amount, minus nothing: none is recorded yet). */
    private function downpaymentDueCents(PhotographyBooking $booking): int
    {
        return app(PaymentRules::class)->downpaymentCents((string) $booking->confirmed_price);
    }

    /**
     * Phase 10J: the owner's next step on one order or booking, in one line (the customer page, where walk-ins are
     * followed because they get no notifications). Words only; null when nothing waits on the owner.
     */
    public function forOwner(PrintingOrder|PhotographyBooking $record): ?string
    {
        $due = $record->amountDue();
        $owes = $due !== null && Money::toCents($due) > 0;
        $amount = $owes ? Money::format($due) : null;

        if ($record instanceof PhotographyBooking) {
            return match (true) {
                $record->isExpired() => 'Expired: reject it or let the customer cancel.',
                $record->status === BookingStatus::Requested => 'Confirm it, offer another time or reject it.',
                $record->status === BookingStatus::Proposed => 'Waiting for the customer to accept the offered time.',
                $record->status === BookingStatus::AwaitingDownpayment => 'Collect the downpayment by '.LocalTime::format($record->downpayment_due_at).', or it is cancelled.',
                $record->status === BookingStatus::Confirmed && $owes => 'Collect '.$amount.' by the day of the shoot ('.LocalTime::format($record->starts_at, 'M j').').',
                $record->status === BookingStatus::Confirmed => 'Mark it completed after the shoot.',
                default => null,
            };
        }

        return match ($record->status) {
            PrintingOrderStatus::Submitted, PrintingOrderStatus::UnderReview => 'Review it and accept it with a price, or reject it.',
            PrintingOrderStatus::Accepted => $record->requires_prepayment && $owes ? 'Collect '.$amount.' before printing.' : 'Start printing.',
            PrintingOrderStatus::Processing => 'Mark it ready for pickup when printed.',
            PrintingOrderStatus::ReadyForPickup => $owes ? 'Collect '.$amount.' at pickup, then release it.' : 'Release it when it is picked up.',
            default => null,
        };
    }

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

        // Phase 10O: the urgent sentence is also the current step's words
        if ($closed === null && ($warning = $this->warning($booking)) !== null && isset($steps[$current])) {
            $steps[$current]['text'] = $status === BookingStatus::Requested ? $steps[$current]['text'].' '.$warning['text'] : $warning['text'];
        }

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

        // Phase 10O: the urgent sentence is also the current step's words
        if ($closed === null && ($warning = $this->warning($order)) !== null && isset($steps[$current])) {
            $steps[$current]['text'] = $warning['text'];
        }

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
