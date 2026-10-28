<?php

namespace App\Http\Requests\Customer;

use App\Enums\PrintingOrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The status filter of "My printing orders". A value that is not a status sends the customer back to the full list. */
class PrintingOrderListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is enforced by the auth + role:customer route middleware
    }

    protected function getRedirectUrl(): string
    {
        return route('customer.printing.index');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['status' => ['nullable', 'string', Rule::enum(PrintingOrderStatus::class)]];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['status.enum' => 'That status filter does not exist, so all your orders are shown.', 'status.string' => 'That status filter does not exist, so all your orders are shown.'];
    }

    public function status(): ?PrintingOrderStatus
    {
        return PrintingOrderStatus::tryFrom((string) $this->validated('status'));
    }
}
