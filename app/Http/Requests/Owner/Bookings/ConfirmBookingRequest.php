<?php

namespace App\Http\Requests\Owner\Bookings;

/**
 * Requested to Confirmed. The price defaults to the estimate; "Confirm anyway" sets aside schedule rules the time now
 * breaks (never a clash with a confirmed booking).
 */
class ConfirmBookingRequest extends BookingActionRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return parent::rules() + [
            'confirmed_price' => $this->optionalPriceRules(),
            'outside_schedule' => ['nullable', 'boolean'],
        ];
    }

    public function outsideSchedule(): bool
    {
        return $this->boolean('outside_schedule');
    }
}
