<?php

namespace App\Http\Requests\Owner\Bookings;

use App\Services\BookingTransitionService;

/** Requested or Proposed to Rejected. The customer sees the reason. */
class RejectBookingRequest extends BookingActionRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:1', 'max:'.BookingTransitionService::REASON_MAX]];
    }

    public function reason(): string
    {
        return (string) $this->text('reason');
    }
}
