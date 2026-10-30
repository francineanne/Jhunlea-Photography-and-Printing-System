<?php

namespace App\Http\Requests\Chat;

use App\Enums\AssistantAuthor;
use App\Services\Assistant\ThreadMessage;

/**
 * A guest's question on the public pages. Only the guest's own message and their own earlier messages are read: any
 * other field the client sends (an "assistant" turn, a role, a price) is ignored.
 */
class GuestAssistantRequest extends ChatRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:'.(int) config('chat.message_max_chars')],
            'history' => ['nullable', 'array', 'max:4'],
            'history.*' => ['string', 'max:'.(int) config('chat.message_max_chars')],
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

    /** @return list<ThreadMessage> */
    public function history(): array
    {
        return array_map(
            fn (string $text) => new ThreadMessage(AssistantAuthor::Customer, $text),
            array_values((array) $this->input('history', [])),
        );
    }
}
