<?php

namespace App\Http\Requests\Owner\Payments;

use App\Enums\PaymentMethod;
use App\Enums\PaymentRecordStatus;
use App\Support\LocalTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
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
            'range' => ['nullable', 'string', Rule::in(['today', 'yesterday', 'all'])],
        ];
    }

    /**
     * Which days the ledger shows (Phase 8B, the counter's working view): `today` by default, when nothing at all is
     * filtered; `today` or `yesterday` when chosen; `custom` for typed dates; `all` when chosen, or when the owner
     * searches or filters without dates (so a search still finds every day, as before).
     */
    public function range(): string
    {
        if ($this->filled('from') || $this->filled('to')) {
            return 'custom';
        }

        $chosen = $this->validated('range');

        if ($chosen !== null) {
            return $chosen;
        }

        return $this->filled('q') || $this->filled('method') || $this->filled('status') ? 'all' : 'today';
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
            'range.*' => 'That choice of days does not exist.',
        ];
    }

    /** @return array{method: ?PaymentMethod, status: ?PaymentRecordStatus, search: string, from: ?string, to: ?string} */
    public function filters(): array
    {
        return [
            'method' => PaymentMethod::tryFrom((string) $this->validated('method')),
            'status' => PaymentRecordStatus::tryFrom((string) $this->validated('status')),
            'search' => trim((string) $this->validated('q')),
            'from' => $this->day() ?? $this->validated('from'),
            'to' => $this->day() ?? $this->validated('to'),
        ];
    }

    /** The one Manila day of the today and yesterday views, else null. */
    private function day(): ?string
    {
        $today = LocalTime::in(Carbon::now('UTC'));

        return match ($this->range()) {
            'today' => $today->format('Y-m-d'),
            'yesterday' => $today->subDay()->format('Y-m-d'),
            default => null,
        };
    }
}
