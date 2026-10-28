<?php

namespace App\Http\Requests\Customer\Bookings;

/** The customer accepts the time the studio proposed. Nothing is typed. */
class AcceptProposalRequest extends BookingResponseRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [];
    }
}
