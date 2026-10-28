<?php

namespace App\Http\Requests\Owner\Orders;

use App\Enums\PrintingOrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The filters of the owner's order list. Anything that does not pass sends the owner back to the full list. */
class OrderListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is enforced by the auth + role:owner route middleware
    }

    protected function getRedirectUrl(): string
    {
        return route('owner.orders.index');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', Rule::enum(PrintingOrderStatus::class)],
            'q' => ['nullable', 'string', 'max:60'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'status.*' => 'That status filter does not exist.',
            'q.max' => 'Search with up to :max characters.',
            'q.string' => 'Search with plain text.',
            'from.date_format' => 'Pick a valid start date.',
            'to.date_format' => 'Pick a valid end date.',
            'to.after_or_equal' => 'The end date must be on or after the start date.',
        ];
    }

    public function status(): ?PrintingOrderStatus
    {
        return PrintingOrderStatus::tryFrom((string) $this->validated('status'));
    }

    public function search(): string
    {
        return trim((string) $this->validated('q'));
    }

    public function from(): ?string
    {
        return $this->validated('from');
    }

    public function to(): ?string
    {
        return $this->validated('to');
    }
}
