<?php

namespace App\Http\Requests\Owner\Payments;

use App\Enums\PaymentMethod;
use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Record the full payment of an existing order or booking at the counter (Phase 8B). There is no amount field:
 * PaymentService uses the confirmed price. `expected_amount` is the amount the owner saw and confirmed (a stale page is
 * refused). `cash_tendered` only works out the change on screen; it is never stored.
 */
class CounterPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // auth + role:owner on the route
    }

    protected function getRedirectUrl(): string
    {
        return route('owner.payments.index');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'payable_type' => ['required', 'string', Rule::in(['printing_order', 'photography_booking'])],
            'payable_id' => ['required', 'integer', 'min:1'],
            'method' => ['required', 'string', Rule::enum(PaymentMethod::class)],
            'gcash_reference' => ['nullable', 'string', 'required_if:method,'.PaymentMethod::Gcash->value, 'regex:'.Payment::GCASH_REFERENCE_PATTERN],
            'expected_amount' => ['required', 'string', 'regex:/^\d{1,8}(\.\d{1,2})?$/'],
            'cash_tendered' => ['nullable', 'string', 'regex:/^\d{1,8}(\.\d{1,2})?$/'],
            'collected' => ['accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'payable_type.*' => 'Choose the order or booking again.',
            'payable_id.*' => 'Choose the order or booking again.',
            'method.*' => 'Choose how the customer paid: cash or GCash.',
            'gcash_reference.required_if' => 'Enter the GCash reference number from the customer’s receipt.',
            'gcash_reference.*' => 'The GCash reference must be 6 to 40 letters, digits or hyphens.',
            'expected_amount.*' => 'Choose the order or booking again.',
            'cash_tendered.*' => 'Type the cash received as a number, or leave it empty.',
            'collected.accepted' => 'Tick the box to confirm you collected the full amount.',
        ];
    }

    public function paymentMethod(): PaymentMethod
    {
        return PaymentMethod::from((string) $this->validated('method'));
    }

    public function gcashReference(): ?string
    {
        return $this->paymentMethod() === PaymentMethod::Gcash ? (string) $this->validated('gcash_reference') : null;
    }
}
