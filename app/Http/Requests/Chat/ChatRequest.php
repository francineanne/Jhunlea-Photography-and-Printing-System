<?php

namespace App\Http\Requests\Chat;

use App\Exceptions\ChatException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The chat endpoints answer JSON {code, message} for every refusal (Phase 7B), validation included. Who may use the
 * conversation is checked in the controller (ConversationPolicy, 404).
 */
abstract class ChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new ChatException('validation', (string) $validator->errors()->first(), 422);
    }

    protected function optionalInt(string $key): ?int
    {
        return $this->filled($key) ? (int) $this->input($key) : null;
    }
}
