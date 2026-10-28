<?php

namespace App\Http\Requests\Owner\Schedule;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The weekly opening hours: seven rows, hours[weekday][open|opens|closes], weekday 0 (Sunday) to 6, times in shop
 * local time on the slot grid (config booking.slot_step_minutes). "acknowledge" confirms saving although confirmed
 * bookings would fall outside the new hours.
 */
class SaveHoursRequest extends FormRequest
{
    public const DAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 0 => 'Sunday'];

    public function authorize(): bool
    {
        return true; // access is enforced by the auth + role:owner route middleware
    }

    protected function getRedirectUrl(): string
    {
        return route('owner.schedule.edit').'#hours';
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'hours' => ['required', 'array'],
            'hours.*' => ['array'],
            'hours.*.open' => ['nullable', 'boolean'],
            'hours.*.opens' => ['nullable', 'date_format:H:i'],
            'hours.*.closes' => ['nullable', 'date_format:H:i'],
            'acknowledge' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'hours.required' => 'Send the opening hours of every day.',
            'hours.*.opens.date_format' => 'Enter opening times like 09:00.',
            'hours.*.closes.date_format' => 'Enter closing times like 17:00.',
        ];
    }

    /** @return array<int, \Closure> */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $sent = array_map('strval', array_keys((array) $this->input('hours')));
            sort($sent);

            if ($sent !== ['0', '1', '2', '3', '4', '5', '6']) {
                $validator->errors()->add('hours', 'Send the opening hours of all seven days.');

                return;
            }

            $step = (int) config('booking.slot_step_minutes');

            foreach ($this->hours() as $weekday => $day) {
                $name = self::DAYS[$weekday];

                if (! $day['open']) {
                    continue;
                }

                if ($day['opens'] === null || $day['closes'] === null) {
                    $validator->errors()->add("hours.{$weekday}", "{$name}: enter both the opening and the closing time, or mark the day closed.");

                    continue;
                }

                if ($day['closes'] <= $day['opens']) {
                    $validator->errors()->add("hours.{$weekday}", "{$name}: the closing time must be after the opening time.");
                }

                foreach ([$day['opens'], $day['closes']] as $time) {
                    [$h, $m] = array_map('intval', explode(':', $time));

                    if (($h * 60 + $m) % $step !== 0) {
                        $validator->errors()->add("hours.{$weekday}", "{$name}: times go in steps of {$step} minutes (for example 09:00 or 09:{$step}).");

                        break;
                    }
                }
            }
        }];
    }

    /** @return array<int, array{open: bool, opens: ?string, closes: ?string}> */
    public function hours(): array
    {
        $hours = [];

        foreach ((array) $this->input('hours') as $weekday => $day) {
            $day = is_array($day) ? $day : [];
            $hours[(int) $weekday] = [
                'open' => filter_var($day['open'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'opens' => is_string($day['opens'] ?? null) && $day['opens'] !== '' ? $day['opens'] : null,
                'closes' => is_string($day['closes'] ?? null) && $day['closes'] !== '' ? $day['closes'] : null,
            ];
        }

        ksort($hours);

        return $hours;
    }

    public function acknowledged(): bool
    {
        return $this->boolean('acknowledge');
    }
}
