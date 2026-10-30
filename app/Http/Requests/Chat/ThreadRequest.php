<?php

namespace App\Http\Requests\Chat;

/** One page of a thread: ?after=<id> (polling) or ?before=<id> (load earlier), never both. */
class ThreadRequest extends ChatRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'after' => ['nullable', 'integer', 'min:0', 'prohibits:before'],
            'before' => ['nullable', 'integer', 'min:1'],
        ];
    }

    // Not after()/before(): FormRequest already uses after() for its own validation hooks
    public function afterId(): ?int
    {
        return $this->optionalInt('after');
    }

    public function beforeId(): ?int
    {
        return $this->optionalInt('before');
    }
}
