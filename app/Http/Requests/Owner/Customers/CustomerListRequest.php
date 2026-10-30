<?php

namespace App\Http\Requests\Owner\Customers;

use Illuminate\Foundation\Http\FormRequest;

/** The search and filter of the customer directory. Anything that does not pass sends the owner back to the full list. */
class CustomerListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is enforced by the auth + role:owner route middleware
    }

    protected function getRedirectUrl(): string
    {
        return route('owner.customers.index');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:60', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'awaiting' => ['nullable', 'string', 'in:1'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'q.max' => 'Search with up to :max characters.',
            'q.*' => 'Search with plain text.',
            'awaiting.*' => 'That filter does not exist.',
            'page.*' => 'That page does not exist.',
        ];
    }

    /** @return array{search: string, awaiting: bool} */
    public function filters(): array
    {
        return [
            'search' => trim((string) $this->validated('q')),
            'awaiting' => $this->validated('awaiting') === '1',
        ];
    }
}
