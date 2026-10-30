<?php

namespace App\Http\Requests\Owner\Settings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The business info on the Settings page: shop name, address, phone and email. Every field may be left empty, which
 * clears it (and hides it on the public site). Plain text only: stored as typed (trimmed) and escaped when shown.
 */
class BusinessInfoRequest extends FormRequest
{
    // Digits, spaces and + ( ) - . / only, with 7 to 15 digits in all, for example "+63 917 123 4567" or "(02) 8123 4567"
    public const PHONE_PATTERN = '/^(?=(?:\D*\d){7,15}\D*$)[0-9+()\-.\/ ]+$/';

    public function authorize(): bool
    {
        return true; // access is enforced by the auth + role:owner route middleware
    }

    protected function getRedirectUrl(): string
    {
        return route('owner.settings.index').'#business';
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $noControl = 'not_regex:/[\x00-\x1F\x7F]/u';

        return [
            'shop_name' => ['nullable', 'string', 'max:80', $noControl],
            // Line breaks are allowed in the address; other control characters are not
            'address' => ['nullable', 'string', 'max:300', 'not_regex:/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/u'],
            'phone' => ['nullable', 'string', 'max:40', 'regex:'.self::PHONE_PATTERN],
            'email' => ['nullable', 'string', 'max:120', 'email:rfc', $noControl],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'shop_name.max' => 'Keep the shop name to :max characters.',
            'shop_name.*' => 'Type the shop name as plain text.',
            'address.max' => 'Keep the address to :max characters.',
            'address.*' => 'Type the address as plain text.',
            'phone.max' => 'Keep the phone number to :max characters.',
            'phone.*' => 'Type a phone number with 7 to 15 digits, for example +63 917 123 4567. Use only digits, spaces and + ( ) - . /',
            'email.max' => 'Keep the email address to :max characters.',
            'email.*' => 'Type a valid email address, for example hello@example.com.',
        ];
    }

    /** @return array{shop_name: ?string, address: ?string, phone: ?string, email: ?string} */
    public function info(): array
    {
        $value = fn (string $field) => is_string($this->validated($field)) && trim($this->validated($field)) !== '' ? trim(str_replace("\r\n", "\n", $this->validated($field))) : null;

        return ['shop_name' => $value('shop_name'), 'address' => $value('address'), 'phone' => $value('phone'), 'email' => $value('email')];
    }
}
