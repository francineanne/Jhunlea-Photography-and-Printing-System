<?php

namespace Database\Factories;

use App\Models\PackageImage;
use App\Models\PhotographyPackage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PackageImage> */
class PackageImageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'photography_package_id' => PhotographyPackage::factory(),
            'path' => 'package-images/1/'.fake()->unique()->lexify('????????').'.jpg',
            'alt_text' => null,
            'is_cover' => false,
            'sort_order' => 0,
        ];
    }

    public function cover(): static
    {
        return $this->state(['is_cover' => true]);
    }
}
