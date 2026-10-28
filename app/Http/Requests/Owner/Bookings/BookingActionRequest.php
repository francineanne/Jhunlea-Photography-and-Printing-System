<?php

namespace App\Http\Requests\Owner\Bookings;

use App\Http\Requests\Concerns\ValidatesConfirmedPrice;
use App\Models\PhotographyBooking;
use App\Services\BookingTransitionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Base of the owner's booking actions. Which moves are allowed is decided by BookingTransitionService and the status
 * table, never here; this checks the owner may act on the booking and validates what was typed.
 */
abstract class BookingActionRequest extends FormRequest
{
    use ValidatesConfirmedPrice;

    public function authorize(): bool
    {
        return Gate::allows('changeStatus', $this->booking());
    }

    /** A failed action returns to the booking, where the errors are shown above the actions. */
    protected function getRedirectUrl(): string
    {
        return route('owner.bookings.show', $this->booking()).'#actions';
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['note' => ['nullable', 'string', 'max:'.BookingTransitionService::NOTE_MAX]];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'note.required' => 'Write a short note for the customer.',
            'note.max' => 'The note can be up to :max characters.',
            'reason.required' => 'Write the reason. The customer will see it.',
            'reason.max' => 'The reason can be up to :max characters.',
        ] + $this->priceMessages();
    }

    public function booking(): PhotographyBooking
    {
        return $this->route('booking');
    }

    public function note(): ?string
    {
        return $this->text('note');
    }

    /** The price typed, or null when the field was left empty (the estimate is used then). */
    public function price(): ?string
    {
        $price = $this->validated('confirmed_price');

        return $price === null || $price === '' ? null : (string) $price;
    }

    /**
     * A price may be left empty (the estimate is used), except where the package is priced on request and there is
     * no estimate to fall back on.
     *
     * @return array<int, string>
     */
    protected function optionalPriceRules(): array
    {
        $rules = $this->priceRules();

        return $this->booking()->estimated_price === null ? $rules : ['nullable', ...array_slice($rules, 1)];
    }

    protected function text(string $key): ?string
    {
        $value = $this->validated($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
