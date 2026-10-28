<?php

namespace App\Http\Requests\Owner\Orders;

/** Asks the customer for more information without changing the status. The customer sees the message. */
class RequestInfoRequest extends OrderActionRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['message' => ['required', 'string', 'min:1', 'max:1000']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['message.required' => 'Write what you need from the customer.', 'message.max' => 'The message can be up to :max characters.'];
    }

    public function infoMessage(): string
    {
        return (string) $this->validated('message');
    }
}
