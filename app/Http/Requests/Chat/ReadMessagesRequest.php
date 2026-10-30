<?php

namespace App\Http\Requests\Chat;

/** Marks a thread read up to a message (up_to), or up to the newest one when none is given. */
class ReadMessagesRequest extends ChatRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['up_to' => ['nullable', 'integer', 'min:1']];
    }

    public function upTo(): ?int
    {
        return $this->optionalInt('up_to');
    }
}
