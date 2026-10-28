<?php

namespace Database\Factories;

use App\Models\BookingReferenceImage;
use App\Models\PhotographyBooking;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<BookingReferenceImage> */
class BookingReferenceImageFactory extends Factory
{
    public function definition(): array
    {
        $stored = Str::random(40).'.jpg';

        return [
            'photography_booking_id' => PhotographyBooking::factory(),
            'original_name' => 'inspiration.jpg',
            'stored_name' => $stored,
            'path' => 'booking-images/1/'.$stored,
            'mime' => 'image/jpeg',
            'size_bytes' => 2048,
            'uploaded_by' => User::factory(),
        ];
    }
}
