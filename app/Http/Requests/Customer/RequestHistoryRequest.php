<?php

namespace App\Http\Requests\Customer;

use App\Enums\BookingStatus;
use App\Enums\PrintingOrderStatus;
use App\Services\RequestHistoryService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The filters of the combined request history. A status belongs to one kind, so it needs that kind chosen. Anything
 * that does not pass sends the customer back to the full list with a message.
 */
class RequestHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // auth + role:customer on the route; the service reads only this customer's rows
    }

    protected function getRedirectUrl(): string
    {
        return route('customer.requests.index');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $statuses = match ($this->input('kind')) {
            'printing' => array_column(PrintingOrderStatus::cases(), 'value'),
            'photography' => array_column(BookingStatus::cases(), 'value'),
            default => [],
        };

        return [
            'kind' => ['nullable', 'string', Rule::in(RequestHistoryService::KINDS)],
            'status' => ['nullable', 'string', Rule::in($statuses)],
            'q' => ['nullable', 'string', 'max:60'],
            'from' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:2100-12-31'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'kind.*' => 'That kind of request does not exist.',
            'status.*' => 'Choose printing or photography first, then one of its statuses.',
            'q.max' => 'Search with up to :max characters.',
            'q.string' => 'Search with plain text.',
            'from.*' => 'Pick a valid start date.',
            'to.after_or_equal' => 'The end date must be on or after the start date.',
            'to.*' => 'Pick a valid end date.',
            'page.*' => 'That page does not exist.',
        ];
    }

    /** @return array{kind: ?string, status: ?string, search: string, from: ?string, to: ?string} */
    public function filters(): array
    {
        return [
            'kind' => $this->validated('kind'),
            'status' => $this->validated('status'),
            'search' => trim((string) $this->validated('q')),
            'from' => $this->validated('from'),
            'to' => $this->validated('to'),
        ];
    }
}
