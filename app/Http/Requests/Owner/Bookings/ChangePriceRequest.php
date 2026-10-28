<?php

namespace App\Http\Requests\Owner\Bookings;

use App\Services\BookingTransitionService;

/** A new confirmed price for a confirmed booking; the history shows "from X to Y" and the reason. */
class ChangePriceRequest extends BookingActionRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['confirmed_price' => $this->priceRules(), 'reason' => ['nullable', 'string', 'max:'.BookingTransitionService::NOTE_MAX]];
    }

    public function newPrice(): string
    {
        return (string) $this->validated('confirmed_price');
    }

    public function reason(): ?string
    {
        return $this->text('reason');
    }
}
