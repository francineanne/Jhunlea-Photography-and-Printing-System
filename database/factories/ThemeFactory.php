<?php

namespace Database\Factories;

use App\Models\Theme;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Theme> */
class ThemeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => Str::title(fake()->unique()->words(2, true)),
            'description' => fake()->sentence(),
            'image_path' => null,
            'color_swatches' => ['#C62828', '#171717', '#F5F5F5'],
            'is_published' => false,
        ];
    }

    public function published(): static
    {
        return $this->state(['is_published' => true]);
    }
}
