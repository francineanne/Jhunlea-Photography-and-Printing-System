<?php

namespace App\Http\Requests\Owner\WalkIn;

use App\Services\BookingService;
use App\Support\LocalTime;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/** A walk-in photography booking (Phase 8A). WalkInBookingService checks the time, the clash and the rules again. */
class StoreWalkInBookingRequest extends WalkInRequest
{
    protected function getRedirectUrl(): string
    {
        return route('owner.bookings.walk-in.create');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['outside_schedule' => $this->boolean('outside_schedule')]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return $this->customerRules() + [
            'package' => ['required', 'integer', 'min:1'],
            'theme' => ['nullable', 'integer', 'min:1'],
            'custom_theme_text' => ['nullable', 'string', 'max:'.BookingService::CUSTOM_THEME_MAX],
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
            'time' => ['required', 'date_format:H:i'],
            'group_size' => ['required', 'integer', 'min:1', 'max:1000'],
            'contact_number' => ['required', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:'.BookingService::NOTES_MAX],
            'confirmed_price' => ['nullable', 'string', 'regex:/^\d{1,8}(\.\d{1,2})?$/'],
            'outside_schedule' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->customerMessages() + [
            'package.*' => 'Choose a package.',
            'theme.*' => BookingService::THEME_MESSAGE,
            'custom_theme_text.*' => BookingService::CUSTOM_THEME_MESSAGE,
            'date.*' => 'Pick the date of the session.',
            'time.*' => 'Pick the start time.',
            'group_size.*' => 'Enter how many people will be in the photos.',
            'contact_number.*' => BookingService::CONTACT_NUMBER_MESSAGE,
            'notes.*' => BookingService::NOTES_MESSAGE,
            'confirmed_price.*' => 'Type the price as a number, for example 1500 or 1500.50, or leave it empty.',
        ];
    }

    /** @return array<int, \Closure> */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->checkCustomer($validator)];
    }

    /** The chosen local date and time as a UTC moment. */
    public function startsAt(): Carbon
    {
        return LocalTime::dayStartUtc((string) $this->validated('date'))
            ->setTimezone(config('app.display_timezone'))
            ->setTimeFromTimeString((string) $this->validated('time'))
            ->utc();
    }
}
