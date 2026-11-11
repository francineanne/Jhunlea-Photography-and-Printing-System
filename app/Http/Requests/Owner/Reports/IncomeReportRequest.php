<?php

namespace App\Http\Requests\Owner\Reports;

use App\Services\Reports\IncomePeriod;
use App\Support\LocalTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * The period of the income report (Phase 8C): a view (daily, weekly, monthly, custom, all), a day inside it, or a custom
 * from / to. With no view, the owner's remembered one. Anything that does not pass sends the owner back to their
 * default view with a message. The same period drives the page, the CSV and the ledger link.
 */
class IncomeReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // auth + role:owner on the route
    }

    protected function getRedirectUrl(): string
    {
        return route('owner.reports.income');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'view' => ['nullable', 'string', Rule::in(array_keys(IncomePeriod::VIEWS))],
            'date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
            'from' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'view.*' => 'That view does not exist. Your usual view is shown.',
            'date.*' => 'Pick a valid date. Your usual view is shown.',
            'from.*' => 'Pick a valid start date. Your usual view is shown.',
            'to.after_or_equal' => 'The end date must be on or after the start date. Your usual view is shown.',
            'to.*' => 'Pick a valid end date. Your usual view is shown.',
            'page.*' => 'That page does not exist. Your usual view is shown.',
        ];
    }

    /** The view the owner chose in this request, or null (then the remembered one applies). */
    public function chosenView(): ?string
    {
        if ($this->filled('from') || $this->filled('to')) {
            return 'custom';
        }

        return $this->validated('view');
    }

    public function period(string $remembered): IncomePeriod
    {
        return IncomePeriod::resolve($this->chosenView() ?? $remembered, $this->validated('date'), $this->validated('from'), $this->validated('to'),
            LocalTime::in(Carbon::now('UTC')));
    }
}
