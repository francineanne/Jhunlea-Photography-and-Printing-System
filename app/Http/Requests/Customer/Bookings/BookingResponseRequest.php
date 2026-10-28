<?php

namespace App\Http\Requests\Customer\Bookings;

use App\Models\PhotographyBooking;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Base of the customer's actions on their own booking (cancel, accept a proposed time, ask for another time). This only
 * checks the booking is theirs; whether the move is allowed right now is BookingTransitionService's decision, so a page
 * that is out of date comes back as a message instead of an error page.
 */
abstract class BookingResponseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('view', $this->booking());
    }

    protected function getRedirectUrl(): string
    {
        return route('customer.bookings.show', $this->booking()).'#respond';
    }

    public function booking(): PhotographyBooking
    {
        return $this->route('booking');
    }

    protected function text(string $key): ?string
    {
        $value = $this->validated($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
