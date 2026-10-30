<?php

namespace App\Http\Requests\Account;

use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Closure;
use Illuminate\Validation\Rule;

/**
 * Name, email and phone. The phone rule is registration's own (RegisterRequest::PHONE_PATTERN). A new email must not
 * be used by anyone else (case does not count) and needs the current password; changing only the name or phone does not.
 */
class UpdateProfileRequest extends AccountRequest
{
    protected $errorBag = 'profile';

    protected string $section = 'profile';

    public const EMAIL_TAKEN = 'That email address is already used by another account.';

    protected function prepareForValidation(): void
    {
        foreach (['name', 'email', 'phone'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }

        // Stored in lower case, as registration does
        if (is_string($this->input('email'))) {
            $this->merge(['email' => strtolower($this->input('email'))]);
        }
    }

    /** True when the email typed differs from the one stored. */
    public function changesEmail(): bool
    {
        return is_string($this->input('email')) && $this->input('email') !== strtolower((string) $this->user()->email);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:80', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'email' => ['bail', 'required', 'string', 'email', 'max:255', function (string $attribute, mixed $value, Closure $fail) {
                $taken = User::query()->whereKeyNot($this->user()->getKey())->whereRaw('LOWER(email) = ?', [mb_strtolower((string) $value)])->exists();

                if ($taken) {
                    $fail(self::EMAIL_TAKEN);
                }
            }],
            'phone' => ['nullable', 'string', 'regex:'.RegisterRequest::PHONE_PATTERN],
            'current_password' => ['bail', Rule::requiredIf(fn () => $this->changesEmail()), 'nullable', 'string', 'current_password'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Type your name.',
            'name.min' => 'Your name needs at least :min characters.',
            'name.max' => 'Keep your name to :max characters.',
            'name.*' => 'Type your name as plain text.',
            'email.required' => 'Type your email address.',
            'email.max' => 'Keep the email address to :max characters.',
            'email.string' => 'Type a valid email address, for example name@example.com.',
            'email.email' => 'Type a valid email address, for example name@example.com.',
            'phone.regex' => RegisterRequest::PHONE_MESSAGE,
            'phone.*' => RegisterRequest::PHONE_MESSAGE,
            'current_password.required' => 'Type your current password to change your email address.',
            'current_password.current_password' => 'That is not your current password. Nothing was changed.',
            'current_password.*' => 'Type your current password to change your email address.',
        ];
    }

    /** @return array{name: string, email: string, phone: ?string} */
    public function profile(): array
    {
        $phone = $this->validated('phone');

        return [
            'name' => (string) $this->validated('name'),
            'email' => (string) $this->validated('email'),
            'phone' => is_string($phone) && $phone !== '' ? $phone : null,
        ];
    }
}
