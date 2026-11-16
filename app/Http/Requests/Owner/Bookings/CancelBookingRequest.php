<?php

namespace App\Http\Requests\Owner\Bookings;

use App\Models\Payment;
use App\Services\BookingTransitionService;
use Illuminate\Validation\Rule;

/**
 * The studio cancels a booking that is not final yet. The reason is optional and the customer sees it. Phase 10B: with
 * a recorded payment the owner chooses keep or refund (refund needs its own reason, kept with the voided payment);
 * BookingTransitionService checks both again on the locked booking.
 */
class CancelBookingRequest extends BookingActionRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:'.BookingTransitionService::NOTE_MAX],
            'payment_choice' => ['nullable', 'string', Rule::in([BookingTransitionService::KEEP_PAYMENT, BookingTransitionService::REFUND_PAYMENT])],
            'refund_reason' => ['nullable', 'string', 'max:'.Payment::VOID_REASON_MAX],
        ];
    }

    public function paymentChoice(): ?string
    {
        return $this->validated('payment_choice');
    }

    public function refundReason(): ?string
    {
        return $this->text('refund_reason');
    }

    public function reason(): ?string
    {
        return $this->text('reason');
    }
}
