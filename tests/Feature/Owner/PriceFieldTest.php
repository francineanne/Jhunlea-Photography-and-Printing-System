<?php

namespace Tests\Feature\Owner;

use App\Enums\BookingStatus;
use App\Enums\PrintingOrderStatus;
use App\Models\PhotographyBooking;
use App\Models\PhotographyPackage;
use App\Models\PrintingOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Booking\SetsUpSchedule;
use Tests\TestCase;

/**
 * Phase 10L: every owner price field starts FILLED (the package's price, the printing estimate, or the current
 * confirmed price), says what it is based on, and never offers the browser's list of old values. Server rules unchanged.
 */
class PriceFieldTest extends TestCase
{
    use RefreshDatabase, SetsUpSchedule;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSchedule();
        $this->owner = User::factory()->owner()->create();
    }

    /** The <input> with this id, as rendered. */
    private function input(string $html, string $id): string
    {
        $this->assertSame(1, preg_match('/<input id="'.preg_quote($id, '/').'"[^>]*>/s', $html, $match), "No input #{$id}");

        return $match[0];
    }

    public function test_the_walk_in_booking_form_carries_each_package_price_for_the_price_field(): void
    {
        $package = PhotographyPackage::factory()->published()->create(['name' => 'Solo Portrait', 'price' => '1200.00', 'price_requires_confirmation' => false]);
        $onRequest = PhotographyPackage::factory()->published()->create(['name' => 'Wedding Day', 'price' => null, 'price_requires_confirmation' => true]);

        $page = $this->actingAs($this->owner)->get(route('owner.bookings.walk-in.create'))->assertOk()
            ->assertViewHas('prices', fn ($prices) => $prices[$package->id] === '1200.00' && $prices[$onRequest->id] === null);
        $input = $this->input($page->getContent(), 'walk-in-price');
        $this->assertStringContainsString('autocomplete="off"', $input);
        $this->assertStringContainsString('inputmode="decimal"', $input);
        $this->assertStringContainsString('x-model="price"', $input);

        // After a refused send with that package picked, it comes back with what was typed
        $this->actingAs($this->owner)->withSession(['_old_input' => ['package' => (string) $package->id, 'confirmed_price' => '999']])
            ->get(route('owner.bookings.walk-in.create'))->assertOk()->assertSee('value="999"', false);
        // ... and with the package's price when nothing was typed
        $page = $this->actingAs($this->owner)->withSession(['_old_input' => ['package' => (string) $package->id]])->get(route('owner.bookings.walk-in.create'));
        $this->assertStringContainsString('value="1200.00"', $this->input($page->getContent(), 'walk-in-price'));
    }

    public function test_confirming_a_booking_starts_at_the_package_estimate(): void
    {
        $booking = PhotographyBooking::factory()->status(BookingStatus::Requested)->at($this->manila('2026-11-20 10:00'))->create(['estimated_price' => '1200.00']);

        $page = $this->actingAs($this->owner)->get(route('owner.bookings.show', $booking))->assertOk()
            ->assertSee('Package price: ₱1,200.00. Change it only if this session costs differently.');
        $input = $this->input($page->getContent(), 'confirm-price');
        $this->assertStringContainsString('value="1200.00"', $input);
        $this->assertStringContainsString('autocomplete="off"', $input);
    }

    public function test_the_change_price_field_starts_at_the_current_price(): void
    {
        $booking = PhotographyBooking::factory()->status(BookingStatus::Confirmed)->at($this->manila('2026-11-20 10:00'))->create(['estimated_price' => '1200.00', 'confirmed_price' => '1350.00']);

        $page = $this->actingAs($this->owner)->get(route('owner.bookings.show', $booking))->assertOk()->assertSee('Current price: ₱1,350.00.');
        $this->assertStringContainsString('value="1350.00"', $this->input($page->getContent(), 'price-new'));
    }

    public function test_accepting_an_order_starts_at_its_estimate_and_a_quote_starts_empty(): void
    {
        $order = PrintingOrder::factory()->status(PrintingOrderStatus::UnderReview)->create(['estimated_price' => '84.50']);
        $quote = PrintingOrder::factory()->status(PrintingOrderStatus::UnderReview)->create(['estimated_price' => null]);

        $page = $this->actingAs($this->owner)->get(route('owner.orders.show', $order))->assertOk()
            ->assertSee('Estimated price: ₱84.50. Change it only if the final price is different.');
        $input = $this->input($page->getContent(), 'accept-price');
        $this->assertStringContainsString('value="84.50"', $input);
        $this->assertStringContainsString('autocomplete="off"', $input);

        $page = $this->actingAs($this->owner)->get(route('owner.orders.show', $quote))->assertOk()->assertSee('No estimate: this is a quoted service. Enter the price.');
        $this->assertStringContainsString('value=""', $this->input($page->getContent(), 'accept-price'));
    }
}
