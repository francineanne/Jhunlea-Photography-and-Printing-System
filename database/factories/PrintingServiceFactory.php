<?php

namespace Database\Factories;

use App\Enums\PricingType;
use App\Models\PrintingService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PrintingService> */
class PrintingServiceFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'name' => Str::title($name),
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'category' => fake()->randomElement(['Documents', 'Photos', 'Large format']),
            'pricing_type' => PricingType::PerPage,
            'base_price' => '5.00',
            'allowed_file_types' => ['pdf', 'jpg'],
            'max_file_size_mb' => 2,
            'instructions' => null,
            'is_published' => false,
            'sort_order' => 0,
        ];
    }

    public function published(): static
    {
        return $this->state(['is_published' => true]);
    }

    public function quotation(): static
    {
        return $this->state(['pricing_type' => PricingType::Quotation, 'base_price' => null]);
    }
}
