<?php

namespace App\Http\Requests\Owner\Schedule;

use App\Support\LocalTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/**
 * Blocks one day or a range of days (shop local calendar days), never in the past and at most
 * config booking.max_lookup_days days at once, with an optional reason.
 */
class BlockDatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is enforced by the auth + role:owner route middleware
    }

    protected function getRedirectUrl(): string
    {
        return route('owner.schedule.edit').'#blocked';
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'reason' => ['nullable', 'string', 'max:'.config('booking.blocked_reason_max')],
            'acknowledge' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'from.required' => 'Choose the day to block.',
            'from.date_format' => 'Choose a valid first day.',
            'to.date_format' => 'Choose a valid last day.',
            'to.after_or_equal' => 'The last day must be on or after the first day.',
            'reason.max' => 'The reason can be up to :max characters.',
        ];
    }

    /** @return array<int, \Closure> */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->hasAny(['from', 'to'])) {
                return;
            }

            if ($this->input('from') < LocalTime::format(Carbon::now('UTC'), 'Y-m-d')) {
                $validator->errors()->add('from', 'A day in the past cannot be blocked.');
            }

            $max = (int) config('booking.max_lookup_days');

            if (count($this->days()) > $max) {
                $validator->errors()->add('to', "Block up to {$max} days at once.");
            }
        }];
    }

    /** @return list<string> every day from the first to the last, Y-m-d */
    public function days(): array
    {
        $from = Carbon::createFromFormat('!Y-m-d', (string) $this->input('from'));
        $to = Carbon::createFromFormat('!Y-m-d', (string) ($this->input('to') ?: $this->input('from')));
        $days = [];

        for ($day = $from->copy(); $day->lte($to) && count($days) <= (int) config('booking.max_lookup_days'); $day->addDay()) {
            $days[] = $day->format('Y-m-d');
        }

        return $days;
    }

    public function reason(): ?string
    {
        $reason = $this->input('reason');

        return is_string($reason) && trim($reason) !== '' ? trim($reason) : null;
    }

    public function acknowledged(): bool
    {
        return $this->boolean('acknowledge');
    }
}
