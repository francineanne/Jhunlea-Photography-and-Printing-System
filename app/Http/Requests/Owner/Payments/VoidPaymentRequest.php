<?php

namespace App\Http\Requests\Owner\Payments;

use App\Models\Payment;

/** Void a payment record: a reason is required. It is kept with the payment and never shown to the customer. */
class VoidPaymentRequest extends PaymentActionRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:'.Payment::VOID_REASON_MAX]];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'Write why this payment record is being cancelled.',
            'reason.string' => 'Write why this payment record is being cancelled.',
            'reason.max' => 'Keep the reason to :max characters.',
        ];
    }

    public function payment(): Payment
    {
        return $this->route('payment');
    }

    public function reason(): string
    {
        return trim((string) $this->validated('reason'));
    }
}
