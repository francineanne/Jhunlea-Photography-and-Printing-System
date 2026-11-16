<?php

namespace App\Http\Requests\Owner\Bookings;

use App\Services\BookingTransitionService;

/** Phase 10B: the studio waives the downpayment of a booking awaiting it. The reason is required and audited. */
class WaiveDownpaymentRequest extends BookingActionRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:'.BookingTransitionService::NOTE_MAX]];
    }

    public function reason(): string
    {
        return (string) $this->text('reason');
    }
}
