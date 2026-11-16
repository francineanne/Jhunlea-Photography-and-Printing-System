<?php

namespace App\Http\Requests\Owner\Customers;

use Illuminate\Foundation\Http\FormRequest;

/** Phase 10B: the owner clears a customer's "pay before printing" flag, with an optional reason for the activity log. */
class ClearPrepayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is enforced by the auth + role:owner route middleware
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:200']];
    }

    public function reason(): ?string
    {
        $reason = $this->validated('reason');

        return is_string($reason) && trim($reason) !== '' ? trim($reason) : null;
    }
}
