<?php

namespace App\Http\Requests\Owner\Schedule;

use Illuminate\Foundation\Http\FormRequest;

/** The booking settings, each within the range in config booking.limits. */
class SaveSettingsRequest extends FormRequest
{
    public const FIELDS = [
        'buffer_minutes' => 'The buffer between sessions',
        'min_notice_hours' => 'The minimum notice',
        'max_advance_days' => 'How far ahead customers may book',
        'concurrent_sessions' => 'Sessions at the same time',
    ];

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

        return $messages;
    }

    /** @return array{buffer_minutes: int, min_notice_hours: int, max_advance_days: int, concurrent_sessions: int} */
    public function settings(): array
    {
        return array_map('intval', $this->safe()->only(array_keys(self::FIELDS)));
    }

    public function acknowledged(): bool
    {
        return $this->boolean('acknowledge');
    }
}
