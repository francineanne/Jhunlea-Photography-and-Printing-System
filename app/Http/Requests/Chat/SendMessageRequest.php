<?php

namespace App\Http\Requests\Chat;

/**
 * A message from the customer or the owner. Only the shape is checked here; MessageService normalizes the body (no
 * control characters, 1000 characters at most), checks the limits and that "about" is the customer's own.
 */
class SendMessageRequest extends ChatRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string'],
            'client_id' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'about' => ['nullable', 'array:type,id'],
            'about.type' => ['required_with:about', 'string'],
            'about.id' => ['required_with:about', 'integer', 'min:1'],
            'from_assistant_conversation' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['body.required' => 'Please type a message first.'];
    }

    public function body(): string
    {
        return (string) $this->input('body');
    }

    public function clientId(): ?string
    {
        return $this->filled('client_id') ? (string) $this->input('client_id') : null;
    }

    /** @return array{type: mixed, id: mixed}|null */
    public function about(): ?array
    {
        return $this->filled('about') ? ['type' => $this->input('about.type'), 'id' => $this->input('about.id')] : null;
    }

    public function fromAssistantConversation(): ?int
    {
        return $this->optionalInt('from_assistant_conversation');
    }
}
