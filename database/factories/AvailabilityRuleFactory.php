<?php

namespace Database\Factories;

use App\Models\AvailabilityRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AvailabilityRule> */
class AvailabilityRuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'weekday' => fake()->unique()->numberBetween(0, 6),
            'is_open' => true,
            'opens_at' => '09:00:00',
            'closes_at' => '17:00:00',
        ];
    }

    public function closed(): static
    {
        return $this->state(['is_open' => false, 'opens_at' => null, 'closes_at' => null]);
    }
}
