<?php

namespace App\Http\Requests\Owner\Bookings;

use App\Services\BookingTransitionService;
use Illuminate\Support\Carbon;

/**
 * Phase 10B: a new downpayment deadline (a Manila date and time) with a reason. Whether it lies after now and before
 * the shoot is BookingTransitionService's rule, checked on the locked booking.
 */
class ExtendDeadlineRequest extends BookingActionRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'due_date' => ['required', 'date_format:Y-m-d'],
            'due_time' => ['required', 'date_format:H:i'],
            'reason' => ['required', 'string', 'max:'.BookingTransitionService::NOTE_MAX],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['due_date.*' => 'Choose the new deadline date.', 'due_time.*' => 'Choose the new deadline time.'] + parent::messages();
    }

    public function dueAt(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d H:i', $this->validated('due_date').' '.$this->validated('due_time'), config('app.display_timezone'))->utc();
    }

    public function reason(): string
    {
        return (string) $this->text('reason');
    }
}
