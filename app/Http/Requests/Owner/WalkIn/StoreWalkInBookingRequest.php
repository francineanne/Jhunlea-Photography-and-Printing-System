<?php

namespace App\Http\Requests\Owner\WalkIn;

use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Models\Payment;
use App\Services\BookingService;
use App\Support\LocalTime;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
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
        $this->merge(['outside_schedule' => $this->boolean('outside_schedule'), 'over_daily_limit' => $this->boolean('over_daily_limit'), 'waive' => $this->boolean('waive')]);
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
            'over_daily_limit' => ['boolean'],
            // Phase 10B: the payment taken now, or the waiver
            'pay_kind' => ['nullable', 'string', Rule::in([PaymentKind::Downpayment->value, PaymentKind::Full->value])],
            'pay_method' => ['nullable', 'string', Rule::enum(PaymentMethod::class)],
            'gcash_reference' => ['nullable', 'string', 'required_if:pay_method,'.PaymentMethod::Gcash->value, 'regex:'.Payment::GCASH_REFERENCE_PATTERN],
            'waive' => ['boolean'],
            'waive_reason' => ['nullable', 'string', 'max:300', 'required_if_accepted:waive'],
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
            'pay_kind.*' => 'Choose the downpayment or the full price.',
            'pay_method.*' => 'Choose how the customer paid: cash or GCash.',
            'gcash_reference.required_if' => 'Enter the GCash reference number from the customer’s receipt.',
            'gcash_reference.*' => 'The GCash reference must be 6 to 40 letters, digits or hyphens.',
            'waive_reason.*' => 'Write why the downpayment is waived (up to 300 characters).',
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

    /** Phase 10B: the downpayment or the full price recorded now; null when the downpayment is waived (or nothing is due). */
    public function payKind(): ?PaymentKind
    {
        return $this->boolean('waive') ? null : PaymentKind::tryFrom((string) $this->validated('pay_kind'));
    }

    public function payMethod(): ?PaymentMethod
    {
        return PaymentMethod::tryFrom((string) $this->validated('pay_method'));
    }

    public function gcashReference(): ?string
    {
        return $this->payMethod() === PaymentMethod::Gcash ? $this->validated('gcash_reference') : null;
    }

    public function waiveReason(): ?string
    {
        return $this->boolean('waive') ? $this->validated('waive_reason') : null;
    }
}
