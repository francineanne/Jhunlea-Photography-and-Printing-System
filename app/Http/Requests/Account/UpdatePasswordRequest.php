<?php

namespace App\Http\Requests\Account;

use App\Support\PasswordRules;

/**
 * Change the password: the current one, then a new one that follows the same rule as registration and password reset
 * (PasswordRules::defaults(), through Password::defaults()) and differs from the current one. Password fields are never
 * flashed back into the page.
 */
class UpdatePasswordRequest extends AccountRequest
{
    protected $errorBag = 'password';

    protected string $section = 'security';

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'current_password' => ['bail', 'required', 'string', 'current_password'],
            'password' => ['bail', 'required', 'string', 'confirmed', 'different:current_password', PasswordRules::defaults()],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'current_password.current_password' => 'That is not your current password. Nothing was changed.',
            'current_password.*' => 'Type your current password.',
            'password.required' => 'Type a new password.',
            'password.string' => 'Type a new password.',
            'password.confirmed' => 'The two new passwords are not the same.',
            'password.different' => 'Choose a password that is different from your current one.',
        ];
    }

    public function newPassword(): string
    {
        return (string) $this->validated('password');
    }
}
