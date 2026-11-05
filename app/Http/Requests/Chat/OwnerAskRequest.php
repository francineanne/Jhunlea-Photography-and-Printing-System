<?php

namespace App\Http\Requests\Chat;

/**
 * The owner's Ask Lumi question (Phase 7E-3a): the message, as long as a customer's may be, and "fresh" to ask without
 * the earlier turns. The owner has ONE conversation; any other field the client sends is ignored.
 */
class OwnerAskRequest extends ChatRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:'.(int) config('chat.message_max_chars')],
            'fresh' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['message.required' => 'Please type a message first.'];
    }

    public function message(): string
    {
        return (string) $this->input('message');
    }

    public function fresh(): bool
    {
        return $this->boolean('fresh');
    }
}
