<?php

namespace App\Http\Requests\Customer\Bookings;

use App\Services\BookingTransitionService;

/** The customer turns down the proposed time and tells the studio what would suit them. */
class AskForAnotherTimeRequest extends BookingResponseRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['note' => ['required', 'string', 'max:'.BookingTransitionService::NOTE_MAX]];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'note.required' => 'Tell the studio which days or times would suit you.',
            'note.max' => 'The note can be up to :max characters.',
        ];
    }

    public function note(): string
    {
        return (string) $this->text('note');
    }
}
