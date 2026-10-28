<?php

namespace App\Http\Requests\Owner\Bookings;

use App\Services\BookingTransitionService;

/** The studio's private note on a booking. Never shown to the customer, never written to the history. */
class InternalNotesRequest extends BookingActionRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['internal_notes' => ['nullable', 'string', 'max:'.BookingTransitionService::INTERNAL_NOTE_MAX]];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['internal_notes.max' => 'Keep the internal note to :max characters.'];
    }

    public function notes(): ?string
    {
        return $this->text('internal_notes');
    }
}
