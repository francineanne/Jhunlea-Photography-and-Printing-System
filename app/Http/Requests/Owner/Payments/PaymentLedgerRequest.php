<?php

namespace App\Http\Requests\Owner\Payments;

use App\Enums\PaymentMethod;
use App\Enums\PaymentRecordStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The filters of the payments ledger. Anything that does not pass sends the owner back to the full ledger. */
class PaymentLedgerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is enforced by the auth + role:owner route middleware
    }

    protected function getRedirectUrl(): string
    {
        return route('owner.payments.index');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'method' => ['nullable', 'string', Rule::enum(PaymentMethod::class)],
            'status' => ['nullable', 'string', Rule::enum(PaymentRecordStatus::class)],
            'q' => ['nullable', 'string', 'max:60'],
            'from' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:2100-12-31'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'method.*' => 'That payment method does not exist.',
            'status.*' => 'That payment status does not exist.',
            'q.max' => 'Search with up to :max characters.',
            'q.string' => 'Search with plain text.',
            'from.*' => 'Pick a valid start date.',
            'to.after_or_equal' => 'The end date must be on or after the start date.',
            'to.*' => 'Pick a valid end date.',
        ];
    }

    /** @return array{method: ?PaymentMethod, status: ?PaymentRecordStatus, search: string, from: ?string, to: ?string} */
    public function filters(): array
    {
        return [
            'method' => PaymentMethod::tryFrom((string) $this->validated('method')),
            'status' => PaymentRecordStatus::tryFrom((string) $this->validated('status')),
            'search' => trim((string) $this->validated('q')),
            'from' => $this->validated('from'),
            'to' => $this->validated('to'),
        ];
    }
}
