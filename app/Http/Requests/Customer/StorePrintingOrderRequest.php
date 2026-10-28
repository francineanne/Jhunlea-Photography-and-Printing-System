<?php

namespace App\Http\Requests\Customer;

use App\Http\Requests\Concerns\ValidatesOrderFiles;
use App\Models\PhotographyBooking;
use App\Services\PrintingOrderService;
use Illuminate\Validation\Validator;

/**
 * The whole wizard in one multipart POST: every step is checked again here, whatever the browser did. The file rules
 * (types, sizes, real content) are OrderFileService's own, so they are written in one place only.
 */
class StorePrintingOrderRequest extends PrintingEstimateRequest
{
    use ValidatesOrderFiles;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return parent::rules() + $this->fileRules(config('printing.max_files_per_order')) + [
            'notes' => ['nullable', 'string', 'max:2000'],
            // Prints after the shoot: the customer's own completed booking, checked again by PrintingOrderService
            'booking' => ['nullable', 'integer'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return parent::messages() + $this->fileMessages() + [
            'notes.max' => 'Special instructions can be up to :max characters.',
            'booking.integer' => PrintingOrderService::BOOKING_MESSAGE,
        ];
    }

    /** @return array<int, \Closure> */
    public function after(): array
    {
        return [
            ...parent::after(),
            fn (Validator $validator) => $this->checkFiles($validator, $this->service()),
            function (Validator $validator) {
                if ($this->filled('booking') && ! $validator->errors()->has('booking') && $this->attachedBooking() === null) {
                    $validator->errors()->add('booking', PrintingOrderService::BOOKING_MESSAGE);
                }
            },
        ];
    }

    /** The booking the prints are for: only the signed-in customer's own completed photoshoot, else null. */
    public function attachedBooking(): ?PhotographyBooking
    {
        $id = filter_var($this->input('booking'), FILTER_VALIDATE_INT);

        if ($id === false || $this->user() === null) {
            return null;
        }

        $booking = PhotographyBooking::query()->where('customer_id', $this->user()->id)->find($id);

        return $booking !== null && $booking->status->allowsPrintOrders() ? $booking : null;
    }

    public function notes(): ?string
    {
        $notes = $this->input('notes');

        return is_string($notes) ? $notes : null;
    }
}
