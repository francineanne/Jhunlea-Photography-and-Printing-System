<?php

namespace App\Http\Requests\Owner\Reports;

use App\Support\LocalTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/** The day of the printable end-of-day summary (Phase 8C): a shop calendar day up to today; none means today. */
class EndOfDayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // auth + role:owner on the route
    }

    protected function getRedirectUrl(): string
    {
        return route('owner.reports.income', ['view' => 'daily']);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:'.$this->today()]];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['date.*' => 'Pick a day up to today for the end-of-day summary.'];
    }

    public function day(): string
    {
        return $this->validated('date') ?? $this->today();
    }

    private function today(): string
    {
        return LocalTime::in(Carbon::now('UTC'))->format('Y-m-d');
    }
}
