<?php

namespace App\Http\Requests\Auth;

use App\Support\PhilippineMobile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    // Phase 10C: the phone rule is App\Support\PhilippineMobile (registration, the account page, walk-ins, bookings).
    // The two names stay for the forms that read them
    public const PHONE_PATTERN = '/^[0-9+\-\s().]{7,20}$/';

    public const PHONE_MESSAGE = PhilippineMobile::MESSAGE;

    public const TERMS_MESSAGE = 'Please agree to the terms and the Data Privacy Act notice to create an account.';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        // A non-string (email[]=x) is left alone so the `string` rule rejects it
        if (is_string($email)) {
            $this->merge(['email' => strtolower(trim($email))]);
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            // Phase 10C: a Philippine mobile number is required, so the studio can reach the customer
            'phone' => ['required', 'string', new PhilippineMobile],
            'password' => ['required', 'confirmed', Password::defaults()],
            'terms' => ['accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['phone.required' => 'Enter your mobile number, for example 0917 123 4567.', 'phone.*' => self::PHONE_MESSAGE, 'terms.accepted' => self::TERMS_MESSAGE, 'terms.*' => self::TERMS_MESSAGE];
    }

    /** The mobile number as it is stored: 09XXXXXXXXX. */
    public function phone(): string
    {
        return (string) PhilippineMobile::normalize((string) $this->validated('phone'));
    }
}
