<?php

namespace Database\Factories;

use App\Models\BlockedDate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BlockedDate> */
class BlockedDateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'date' => fake()->unique()->dateTimeBetween('+1 day', '+1 year')->format('Y-m-d'),
            'reason' => null,
            'created_by' => null,
        ];
    }
}
