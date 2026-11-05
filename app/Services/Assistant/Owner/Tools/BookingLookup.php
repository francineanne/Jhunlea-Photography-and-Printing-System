<?php

namespace App\Services\Assistant\Owner\Tools;

use App\Models\PhotographyBooking;
use App\Models\User;
use App\Services\Assistant\Owner\OwnerTool;
use App\Services\Assistant\Owner\ToolResult;

/** One photography booking by its PB reference. */
class BookingLookup extends OwnerTool
{
    public function name(): string
    {
        return 'booking_lookup';
    }

    public function description(): string
    {
        return 'One photography booking by its reference (PB-000123): status, customer name, package, session time, prices and payment.';
    }

    public function parameters(): array
    {
        return ['reference' => ['type' => 'string', 'required' => true, 'max_length' => 20, 'description' => 'PB-000123']];
    }

    protected function handle(array $params, User $owner): ToolResult
    {
        $result = new ToolResult($this->name());
        $reference = OrderLookup::normalize($params['reference'], 'PB');
        $booking = $reference === null ? null : PhotographyBooking::query()->with(['customer:id,name', 'recordedPayment'])->where('reference_number', $reference)->first();

        if ($booking === null) {
            $result->question = 'I could not find a booking with that reference. Which booking do you mean (for example PB-000123)?';

            return $result;
        }

        $result->data = RecordFacts::booking($booking, $result);
        $result->card($booking->reference_number, route('owner.bookings.show', $booking));

        return $result;
    }
}
