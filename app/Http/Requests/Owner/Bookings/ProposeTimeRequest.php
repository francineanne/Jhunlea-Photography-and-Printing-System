<?php

namespace App\Http\Requests\Owner\Bookings;

use App\Http\Requests\Customer\StorePhotographyBookingRequest;
use App\Services\BookingTransitionService;
use Illuminate\Support\Carbon;

/** Requested to Proposed: another free time, a note the customer reads, and a price when the package has none. */
class ProposeTimeRequest extends BookingActionRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'starts_at' => ['required', 'string', 'date_format:'.StorePhotographyBookingRequest::STARTS_AT_FORMAT],
            'note' => ['required', 'string', 'max:'.BookingTransitionService::NOTE_MAX],
            'confirmed_price' => $this->optionalPriceRules(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return parent::messages() + [
            'starts_at.required' => 'Choose the time you want to offer.',
            'starts_at.*' => 'Choose a time from the list.',
        ];
    }

    public function startsAt(): Carbon
    {
        return Carbon::createFromFormat(StorePhotographyBookingRequest::STARTS_AT_FORMAT, (string) $this->validated('starts_at'), 'UTC');
    }
}
