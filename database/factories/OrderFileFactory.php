<?php

namespace Database\Factories;

use App\Models\OrderFile;
use App\Models\PrintingOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<OrderFile> */
class OrderFileFactory extends Factory
{
    public function definition(): array
    {
        $stored = Str::random(40).'.pdf';

        return [
            'printing_order_id' => PrintingOrder::factory(),
            'original_name' => 'sample.pdf',
            'stored_name' => $stored,
            'path' => 'printing-orders/1/'.$stored,
            'mime' => 'application/pdf',
            'size_bytes' => 1024,
            'uploaded_by' => User::factory(),
        ];
    }
}
