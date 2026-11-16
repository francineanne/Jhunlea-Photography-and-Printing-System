<?php

namespace App\Http\Requests\Owner\Schedule;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The booking settings, each within the range in config booking.limits. The daily limit may be left empty (no limit).
 * "Sessions at the same time" is gone since Phase 10A: the shop has one studio, so it is always 1.
 */
class SaveSettingsRequest extends FormRequest
{
    public const FIELDS = [
        'buffer_minutes' => 'The buffer between sessions',
        'min_notice_hours' => 'The minimum notice',
        'max_advance_days' => 'How far ahead customers may book',
        'request_hold_hours' => 'How long a request holds its time',
    ];

    public const DAILY_LIMIT = 'max_sessions_per_day';

    public const DAILY_LIMIT_LABEL = 'Maximum photoshoots per day';

    public function authorize(): bool
    {
        return true; // access is enforced by the auth + role:owner route middleware
    }

    protected function getRedirectUrl(): string
    {
        return route('owner.schedule.edit').'#settings';
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $rules = ['acknowledge' => ['nullable', 'boolean']];

        foreach (array_keys(self::FIELDS) as $field) {
            [$min, $max] = config("booking.limits.{$field}");
            $rules[$field] = ['required', 'integer', "min:{$min}", "max:{$max}"];
        }

        [$min, $max] = config('booking.limits.'.self::DAILY_LIMIT);
        $rules[self::DAILY_LIMIT] = ['nullable', 'integer', "min:{$min}", "max:{$max}"];

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $messages = [];

        foreach (self::FIELDS as $field => $label) {
            [$min, $max] = config("booking.limits.{$field}");
            foreach (['required', 'integer', 'min', 'max'] as $rule) {
                $messages["{$field}.{$rule}"] = "{$label} must be a whole number from {$min} to {$max}.";
            }
        }

        [$min, $max] = config('booking.limits.'.self::DAILY_LIMIT);
        $messages[self::DAILY_LIMIT.'.*'] = self::DAILY_LIMIT_LABEL." must be a whole number from {$min} to {$max}, or empty for no limit.";

        return $messages;
    }

    /** @return array{buffer_minutes: int, min_notice_hours: int, max_advance_days: int, request_hold_hours: int, max_sessions_per_day: ?int} */
    public function settings(): array
    {
        $limit = $this->validated(self::DAILY_LIMIT);

        return array_map('intval', $this->safe()->only(array_keys(self::FIELDS))) + [self::DAILY_LIMIT => $limit === null || $limit === '' ? null : (int) $limit];
    }

    public function acknowledged(): bool
    {
        return $this->boolean('acknowledge');
    }
}
