<?php

namespace App\Http\Requests\Owner\Orders;

/** Under review to Accepted. The confirmed price is required and saved in the same transaction. */
class AcceptOrderRequest extends OrderActionRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return parent::rules() + ['confirmed_price' => $this->priceRules()];
    }

    public function price(): string
    {
        return (string) $this->validated('confirmed_price');
    }
}
