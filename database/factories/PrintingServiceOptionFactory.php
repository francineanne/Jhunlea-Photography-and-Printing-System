<?php

namespace Database\Factories;

use App\Enums\OptionGroup;
use App\Models\PrintingService;
use App\Models\PrintingServiceOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PrintingServiceOption> */
class PrintingServiceOptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'printing_service_id' => PrintingService::factory(),
            'group' => OptionGroup::PaperSize,
            'label' => fake()->unique()->words(2, true),
            'price_modifier' => '0.00',
            'is_active' => true,
        ];
    }
}
