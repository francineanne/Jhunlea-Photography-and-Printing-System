<?php

namespace App\Http\Requests\Owner\Orders;

/** Under review to Accepted. The confirmed price is required and saved in the same transaction. */
class AcceptOrderRequest extends OrderActionRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        // Phase 10B: "prepay" is the owner's "pay before printing" tick; missing means the automatic answer
        return parent::rules() + ['confirmed_price' => $this->priceRules(), 'prepay' => ['nullable', 'boolean']];
    }

    /** Null when the form did not send the tick (the price and the customer's flag then decide). */
    public function prepay(): ?bool
    {
        return $this->has('prepay') ? $this->boolean('prepay') : null;
    }

    public function price(): string
    {
        return (string) $this->validated('confirmed_price');
    }
}
