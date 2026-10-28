<?php

namespace App\Http\Requests\Customer\Bookings;

use App\Enums\BookingStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The status filter of "My bookings". A value that is not a status sends the customer back to the full list. */
class BookingListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is enforced by the auth + role:customer route middleware
    }

    protected function getRedirectUrl(): string
    {
        return route('customer.bookings.index');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['status' => ['nullable', 'string', Rule::enum(BookingStatus::class)]];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['status.*' => 'That status filter does not exist, so all your bookings are shown.'];
    }

    public function status(): ?BookingStatus
    {
        return BookingStatus::tryFrom((string) $this->validated('status'));
    }
}
