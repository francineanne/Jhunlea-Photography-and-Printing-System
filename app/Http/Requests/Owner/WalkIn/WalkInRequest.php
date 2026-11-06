<?php

namespace App\Http\Requests\Owner\WalkIn;

use App\Http\Requests\Auth\RegisterRequest;
use App\Services\WalkIn\WalkInCustomerService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Base of the two counter forms (Phase 8A). The customer is either one the owner picked (customer_id) or a new walk-in
 * typed in (name, optional phone with registration's own phone rule). counter_token is the form's one-time token, so a
 * double click or a retry saves one record. Prices, statuses and the source are never read from the request.
 */
abstract class WalkInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // auth + role:owner on the route
    }

    /** @return array<string, array<int, mixed>> */
    protected function customerRules(): array
    {
        return [
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'new_customer_name' => ['nullable', 'string', 'max:'.WalkInCustomerService::NAME_MAX, 'not_regex:/[\x00-\x1F\x7F]/u'],
            'new_customer_phone' => ['nullable', 'string', 'regex:'.RegisterRequest::PHONE_PATTERN],
            'counter_token' => ['required', 'string', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    protected function customerMessages(): array
    {
        return [
            'customer_id.*' => 'Choose the customer again.',
            'new_customer_name.max' => 'Keep the name to :max characters.',
            'new_customer_name.*' => 'Type the customer’s name as plain text.',
            'new_customer_phone.*' => RegisterRequest::PHONE_MESSAGE,
            'counter_token.*' => 'Reload the page and try again.',
        ];
    }

    /** Exactly one way to say who the customer is. */
    protected function checkCustomer(Validator $validator): void
    {
        $picked = $this->filled('customer_id');
        $typed = is_string($this->input('new_customer_name')) && trim($this->input('new_customer_name')) !== '';

        if (! $picked && ! $typed) {
            $validator->errors()->add('customer', 'Pick a customer, or type the name of a new walk-in customer.');
        }
    }

    public function customerId(): ?int
    {
        return $this->filled('customer_id') ? (int) $this->validated('customer_id') : null;
    }

    public function newCustomerName(): string
    {
        return trim((string) $this->validated('new_customer_name'));
    }

    public function newCustomerPhone(): ?string
    {
        $phone = trim((string) $this->validated('new_customer_phone'));

        return $phone === '' ? null : $phone;
    }

    public function counterToken(): string
    {
        return (string) $this->validated('counter_token');
    }
}
