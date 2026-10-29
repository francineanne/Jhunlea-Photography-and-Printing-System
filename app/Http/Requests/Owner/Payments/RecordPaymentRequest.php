<?php

namespace App\Http\Requests\Owner\Payments;

use App\Enums\PaymentMethod;
use App\Models\Payment;
use Illuminate\Validation\Rule;

/**
 * Record a payment: the method and, for GCash, its reference. There is no amount field: PaymentService uses the
 * confirmed price. `expected_amount` is the amount the owner saw and confirmed, used only to catch a stale page.
 */
class RecordPaymentRequest extends PaymentActionRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'method' => ['required', 'string', Rule::enum(PaymentMethod::class)],
            'gcash_reference' => ['nullable', 'string', 'required_if:method,'.PaymentMethod::Gcash->value, 'regex:'.Payment::GCASH_REFERENCE_PATTERN],
            'expected_amount' => ['nullable', 'string', 'regex:/^\d{1,8}(\.\d{1,2})?$/'],
            'confirm' => ['accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'method.required' => 'Choose how the customer paid: cash or GCash.',
            'method.*' => 'Choose how the customer paid: cash or GCash.',
            'gcash_reference.required_if' => 'Enter the GCash reference number from the customer’s receipt.',
            'gcash_reference.regex' => 'The GCash reference must be 6 to 40 letters, digits or hyphens.',
            'gcash_reference.string' => 'The GCash reference must be 6 to 40 letters, digits or hyphens.',
            'expected_amount.*' => 'Reload the page and record the payment again.',
            'confirm.accepted' => 'Tick the box to confirm you received the full amount.',
        ];
    }

    public function paymentMethod(): PaymentMethod
    {
        return PaymentMethod::from((string) $this->validated('method'));
    }

    public function gcashReference(): ?string
    {
        $value = $this->validated('gcash_reference');

        return $this->paymentMethod() === PaymentMethod::Gcash && is_string($value) ? $value : null;
    }

    public function expectedAmount(): ?string
    {
        $value = $this->validated('expected_amount');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
