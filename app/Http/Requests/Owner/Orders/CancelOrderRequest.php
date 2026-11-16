<?php

namespace App\Http\Requests\Owner\Orders;

use App\Models\Payment;
use App\Services\StatusTransitionService;
use Illuminate\Validation\Rule;

/**
 * The shop cancels an order that is Submitted, Under review or Accepted, with an optional note for the history. Phase
 * 10B: with a recorded payment the owner chooses keep or refund (a refund needs its reason).
 */
class CancelOrderRequest extends OrderActionRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return parent::rules() + [
            'payment_choice' => ['nullable', 'string', Rule::in([StatusTransitionService::KEEP_PAYMENT, StatusTransitionService::REFUND_PAYMENT])],
            'refund_reason' => ['nullable', 'string', 'max:'.Payment::VOID_REASON_MAX],
        ];
    }

    /** @return array{payment_choice: ?string, refund_reason: ?string} */
    public function paymentOptions(): array
    {
        return ['payment_choice' => $this->validated('payment_choice'), 'refund_reason' => $this->validated('refund_reason')];
    }
}
