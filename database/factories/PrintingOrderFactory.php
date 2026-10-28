<?php

namespace Database\Factories;

use App\Enums\PrintingOrderStatus;
use App\Models\PrintingOrder;
use App\Models\PrintingService;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PrintingOrder> */
class PrintingOrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'reference_number' => 'PR-9'.str_pad((string) fake()->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'customer_id' => User::factory(),
            'printing_service_id' => PrintingService::factory()->published(),
            'snapshot' => [
                'service' => ['name' => 'Sample service', 'pricing_type' => 'per_page', 'base_price' => '5.00'],
                'options' => [],
                'pages' => 1,
                'unit_price' => '5.00',
            ],
            'quantity' => 1,
            'notes' => null,
            'status' => PrintingOrderStatus::Submitted,
            'estimated_price' => '5.00',
            'confirmed_price' => null,
            'rejection_reason' => null,
            'info_request' => null,
        ];
    }

    public function status(PrintingOrderStatus $status): static
    {
        return $this->state(['status' => $status]);
    }

    public function rejected(string $reason = 'The file cannot be printed.'): static
    {
        return $this->state(['status' => PrintingOrderStatus::Rejected, 'rejection_reason' => $reason]);
    }

    public function forCustomer(User $customer): static
    {
        return $this->state(['customer_id' => $customer->id]);
    }
}
