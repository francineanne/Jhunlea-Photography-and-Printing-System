<?php

namespace Tests\Feature\Booking;

use App\Enums\BookingStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Exceptions\BookingTransitionException;
use App\Models\PhotographyBooking;
use App\Models\User;
use App\Services\BookingTransitionService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SetsPaymentRules;
use Tests\TestCase;

/**
 * Phase 10P: "Confirm" is for a Requested booking only. Confirming one that already waits for its downpayment (or is
 * confirmed) used to write a second history row and quietly move the deadline and the price; now it is refused and
 * changes nothing. The payment path (Awaiting downpayment -> Confirmed) is untouched.
 */
class ConfirmOnlyRequestsTest extends TestCase
{
    use RefreshDatabase, SetsPaymentRules, SetsUpSchedule;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSchedule();
        $this->owner = User::factory()->owner()->create();
    }

    private function request(): PhotographyBooking
    {
        return PhotographyBooking::factory()->status(BookingStatus::Requested)->at($this->manila('2026-11-12 10:00'))->create(['estimated_price' => '2000.00']);
    }

    private function snapshot(PhotographyBooking $booking): array
    {
        $fresh = $booking->fresh();

        return [$fresh->status, $fresh->confirmed_price, $fresh->downpayment_due_at?->toIso8601String(), $fresh->statusHistories()->count()];
    }

    public function test_confirming_an_awaiting_downpayment_booking_again_is_refused_and_changes_nothing(): void
    {
        $service = app(BookingTransitionService::class);
        $booking = $service->confirm($this->request(), $this->owner);
        $this->assertSame(BookingStatus::AwaitingDownpayment, $booking->fresh()->status);
        $before = $this->snapshot($booking);

        // An hour later, another price: still refused, nothing moves
        $this->travel(1)->hours();
        try {
            $service->confirm($booking->fresh(), $this->owner, '3000.00');
            $this->fail('A second confirm must be refused.');
        } catch (BookingTransitionException $e) {
            $this->assertSame('This booking is already confirmed.', $e->getMessage());
        }

        $this->assertEquals($before, $this->snapshot($booking));
    }

    public function test_confirming_a_confirmed_booking_is_refused(): void
    {
        $this->withoutDownpaymentsOrPrepayment();
        $booking = app(BookingTransitionService::class)->confirm($this->request(), $this->owner);
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
        $before = $this->snapshot($booking);

        $this->expectExceptionMessage('This booking is already confirmed.');
        try {
            app(BookingTransitionService::class)->confirm($booking->fresh(), $this->owner);
        } finally {
            $this->assertEquals($before, $this->snapshot($booking));
        }
    }

    public function test_the_page_drops_the_confirm_panel_and_a_second_post_is_refused(): void
    {
        $booking = $this->request();
        $page = fn () => $this->actingAs($this->owner)->get(route('owner.bookings.show', $booking))->assertOk();

        $page()->assertSee('Confirm the requested time');
        $this->actingAs($this->owner)->post(route('owner.bookings.confirm', $booking), ['confirmed_price' => '2000.00'])->assertRedirect();
        $this->assertSame(BookingStatus::AwaitingDownpayment, $booking->fresh()->status);
        $before = $this->snapshot($booking);

        $page()->assertDontSee('Confirm the requested time');

        // The same post again (a double click, an old tab): refused with the plain sentence, nothing written
        $this->actingAs($this->owner)->post(route('owner.bookings.confirm', $booking), ['confirmed_price' => '2500.00'])
            ->assertSessionHas('error', 'This booking is already confirmed.');
        $this->assertEquals($before, $this->snapshot($booking));
    }

    public function test_the_payment_still_confirms_a_booking_awaiting_its_downpayment(): void
    {
        $booking = app(BookingTransitionService::class)->confirm($this->request(), $this->owner);

        app(PaymentService::class)->record($booking->fresh(), $this->owner, PaymentMethod::Cash, null, null, PaymentKind::Downpayment);

        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
    }
}
