<?php

namespace App\Http\Requests\Customer\Bookings;

use App\Services\BookingTransitionService;

/** The customer cancels their own booking; the cutoff for a confirmed one is checked by BookingTransitionService. */
class CancelBookingRequest extends BookingResponseRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:'.BookingTransitionService::NOTE_MAX]];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['reason.max' => 'The reason can be up to :max characters.'];
    }

    public function reason(): ?string
    {
        return $this->text('reason');
    }
}
