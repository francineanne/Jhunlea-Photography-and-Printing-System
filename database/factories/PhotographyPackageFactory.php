<?php

namespace Database\Factories;

use App\Models\PhotographyPackage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PhotographyPackage> */
class PhotographyPackageFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'name' => Str::title($name),
            'slug' => Str::slug($name),
            'category' => fake()->randomElement(['Portrait', 'Family', 'Graduation']),
            'short_description' => fake()->sentence(),
            'description' => fake()->paragraph(),
            'price' => '1500.00',
            'price_requires_confirmation' => false,
            'duration_minutes' => 60,
            'max_people' => 2,
            'digital_outputs' => 10,
            'inclusions' => ['Studio lighting', 'Edited digital photos'],
            'terms' => null,
            'is_published' => false,
            'is_featured' => false,
            'sort_order' => 0,
        ];
    }

    public function published(): static
    {
        return $this->state(['is_published' => true]);
    }

    public function featured(): static
    {
        return $this->state(['is_featured' => true]);
    }
}
