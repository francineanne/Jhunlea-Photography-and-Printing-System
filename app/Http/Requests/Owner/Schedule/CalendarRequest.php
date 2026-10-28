<?php

namespace App\Http\Requests\Owner\Schedule;

use App\Support\LocalTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** The calendar's view (month, week or day) and the day it shows. Anything else sends the owner to this month. */
class CalendarRequest extends FormRequest
{
    public const VIEWS = ['month', 'week', 'day'];

    public function authorize(): bool
    {
        return true; // access is enforced by the auth + role:owner route middleware
    }

    protected function getRedirectUrl(): string
    {
        return route('owner.schedule.index');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'view' => ['nullable', 'string', Rule::in(self::VIEWS)],
            'date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['view.*' => 'That calendar view does not exist.', 'date.*' => 'That date is not valid.'];
    }

    public function calendarView(): string
    {
        return $this->validated('view') ?? 'month';
    }

    /** The day to show, in shop local time; today when none was chosen. */
    public function calendarDate(): string
    {
        return $this->validated('date') ?? LocalTime::format(Carbon::now('UTC'), 'Y-m-d');
    }
}
