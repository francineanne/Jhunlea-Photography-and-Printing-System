<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/** Phase 10C: the email, the 6-digit code from the email and the new password (with its confirmation). */
class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'code' => ['required', 'string', 'regex:/^\s*\d{6}\s*$/'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['code.required' => 'Enter the 6-digit code from the email.', 'code.regex' => 'The code is 6 digits, for example 042719.'];
    }

    protected function prepareForValidation(): void
    {
        // "123 456" from a phone's copy and paste still reads as one code
        if (is_string($this->input('code'))) {
            $this->merge(['code' => preg_replace('/\s+/', '', $this->input('code'))]);
        }
    }
}
