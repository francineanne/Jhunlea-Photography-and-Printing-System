<?php

namespace Database\Factories;

use App\Enums\PrintingOrderStatus;
use App\Models\OrderStatusHistory;
use App\Models\PrintingOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrderStatusHistory> */
class OrderStatusHistoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'printing_order_id' => PrintingOrder::factory(),
            'from_status' => null,
            'to_status' => PrintingOrderStatus::Submitted,
            'changed_by' => User::factory(),
            'note' => null,
        ];
    }
}
