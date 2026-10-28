<?php

namespace Database\Factories;

use App\Enums\BookingStatus;
use App\Models\PhotographyBooking;
use App\Models\PhotographyPackage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/** @extends Factory<PhotographyBooking> */
class PhotographyBookingFactory extends Factory
{
    public function definition(): array
    {
        $start = Carbon::now('UTC')->addDays(10)->setTime(2, 0);

        return [
            'reference_number' => 'PB-9'.str_pad((string) fake()->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'customer_id' => User::factory(),
            'photography_package_id' => PhotographyPackage::factory()->published(),
            'theme_id' => null,
            'custom_theme_text' => null,
            'snapshot' => $this->snapshot(60),
            'starts_at' => $start,
            'ends_at' => $start->copy()->addMinutes(60),
            'requested_starts_at' => $start,
            'group_size' => 1,
            'contact_number' => '0917 123 4567',
            'notes' => null,
            'wants_prints' => false,
            'status' => BookingStatus::Requested,
            'estimated_price' => '1000.00',
            'confirmed_price' => null,
        ];
    }

    public function status(BookingStatus $status): static
    {
        return $this->state(['status' => $status]);
    }

    public function forCustomer(User $customer): static
    {
        return $this->state(['customer_id' => $customer->id]);
    }

    /** A session at this UTC time; the length goes into the snapshot too (60 minutes unless given). */
    public function at(Carbon $startUtc, int $minutes = 60): static
    {
        return $this->state(fn () => [
            'starts_at' => $startUtc->copy(),
            'ends_at' => $startUtc->copy()->addMinutes($minutes),
            'requested_starts_at' => $startUtc->copy(),
            'snapshot' => $this->snapshot($minutes),
        ]);
    }

    /** @return array<string, mixed> */
    private function snapshot(int $minutes): array
    {
        return [
            'package' => ['name' => 'Sample package', 'duration_minutes' => $minutes, 'price_case' => 'fixed', 'price_text' => '₱1,000.00', 'inclusions' => []],
            'theme' => null,
        ];
    }
}
