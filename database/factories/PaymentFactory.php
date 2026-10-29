<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\PaymentRecordStatus;
use App\Models\Payment;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * Payments for tests. The app records them only through PaymentService; this writes rows directly, for setting up
 * a state (a paid order, a voided record) before the code under test runs.
 *
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'reference_number' => 'PAY-9'.str_pad((string) fake()->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'amount' => '100.00',
            'method' => PaymentMethod::Cash,
            'gcash_reference' => null,
            'received_by' => User::factory()->owner(),
            'received_at' => Carbon::now('UTC'),
            'status' => PaymentRecordStatus::Recorded,
            'active' => Payment::ACTIVE,
        ];
    }

    /** For this order or booking, at its confirmed price. */
    public function paying(PrintingOrder|PhotographyBooking $payable): static
    {
        return $this->state(fn () => [
            'payable_type' => $payable->getMorphClass(),
            'payable_id' => $payable->getKey(),
            'amount' => $payable->confirmed_price ?? '100.00',
        ]);
    }

    public function gcash(string $reference = 'GC-1234567'): static
    {
        return $this->state(['method' => PaymentMethod::Gcash, 'gcash_reference' => $reference]);
    }

    public function voided(string $reason = 'Recorded on the wrong order.'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentRecordStatus::Voided,
            'active' => null,
            'voided_by' => $attributes['received_by'],
            'voided_at' => Carbon::now('UTC'),
            'void_reason' => $reason,
        ]);
    }
}
