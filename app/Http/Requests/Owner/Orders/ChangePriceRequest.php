<?php

namespace App\Http\Requests\Owner\Orders;

/** Changes the confirmed price while the order is Accepted or Processing. */
class ChangePriceRequest extends OrderActionRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['confirmed_price' => $this->priceRules(), 'reason' => ['nullable', 'string', 'max:500']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return parent::messages() + ['reason.max' => 'The reason can be up to :max characters.'];
    }

    public function price(): string
    {
        return (string) $this->validated('confirmed_price');
    }

    public function reason(): ?string
    {
        $reason = $this->validated('reason');

        return is_string($reason) ? $reason : null;
    }
}
