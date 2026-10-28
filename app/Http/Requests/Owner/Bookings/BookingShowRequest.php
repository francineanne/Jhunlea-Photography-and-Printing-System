<?php

namespace App\Http\Requests\Owner\Bookings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** The owner's booking page. ?propose_from=Y-m-d picks the first day of the free times offered for a proposal. */
class BookingShowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('view', $this->route('booking'));
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [];
    }

    /** A day in the address that is not a real date is ignored, never an error. */
    public function proposeFrom(): ?string
    {
        $value = $this->query('propose_from');

        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year) ? $value : null;
    }
}
