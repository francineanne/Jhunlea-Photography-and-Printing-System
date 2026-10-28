<?php

namespace App\Http\Requests\Owner\Orders;

/** Under review to Rejected. The customer sees the reason. */
class RejectOrderRequest extends OrderActionRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:1', 'max:1000']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['reason.required' => 'Write the reason. The customer will see it.', 'reason.max' => 'The reason can be up to :max characters.'];
    }

    public function reason(): string
    {
        return (string) $this->validated('reason');
    }
}
