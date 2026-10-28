<?php

namespace Database\Factories;

use App\Enums\BookingStatus;
use App\Models\BookingStatusHistory;
use App\Models\PhotographyBooking;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BookingStatusHistory> */
class BookingStatusHistoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'photography_booking_id' => PhotographyBooking::factory(),
            'from_status' => null,
            'to_status' => BookingStatus::Requested,
            'event' => null,
            'changed_by' => User::factory(),
            'note' => null,
        ];
    }
}
