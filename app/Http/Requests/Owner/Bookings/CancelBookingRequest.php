<?php

namespace App\Http\Requests\Owner\Bookings;

use App\Services\BookingTransitionService;

/** The studio cancels a booking that is not final yet. The reason is optional and the customer sees it. */
class CancelBookingRequest extends BookingActionRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:'.BookingTransitionService::NOTE_MAX]];
    }

    public function reason(): ?string
    {
        return $this->text('reason');
    }
}
