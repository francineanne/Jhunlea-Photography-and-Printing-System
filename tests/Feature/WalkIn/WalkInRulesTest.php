<?php

namespace Tests\Feature\WalkIn;

use App\Enums\AuditAction;
use App\Enums\BookingStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\PrintingOrderStatus;
use App\Exceptions\InvalidStatusTransitionException;
use App\Http\Requests\Owner\WalkIn\StoreWalkInBookingRequest;
use App\Models\AuditLog;
use App\Models\PhotographyBooking;
use App\Models\PhotographyPackage;
use App\Models\PrintingOrder;
use App\Models\PrintingService;
use App\Models\User;
use App\Services\AvailabilityService;
use App\Services\BookingTransitionService;
use App\Services\DownpaymentExpiryService;
use App\Services\PaymentService;
use App\Services\StatusTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Booking\SetsUpSchedule;
use Tests\TestCase;

/**
 * Phase 10J: walk-ins follow the SAME rules as online customers (scheduling, downpayment, pay-first printing), give
 * ONE phone, start in the ONE Walk-in area and are followed on their customer page. Now is Monday 2 November 2026,
 * 08:00 in Manila; the studio opens Monday to Saturday 9:00 to 17:00; the downpayment is 50% (the defaults).
 */
class WalkInRulesTest extends TestCase
{
    use RefreshDatabase, SetsUpSchedule;

    private User $owner;

    private PhotographyPackage $package;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSchedule(buffer: 30);
        $this->owner = User::factory()->owner()->create();
        $this->package = PhotographyPackage::factory()->published()->create(['name' => 'Solo Portrait', 'duration_minutes' => 60, 'max_people' => 3, 'price' => '1500.00', 'price_requires_confirmation' => false]);
    }

    private function book(array $overrides = [])
    {
        return $this->actingAs($this->owner)->post(route('owner.bookings.walk-in.store'), array_replace([
            'counter_token' => (string) Str::uuid(), 'new_customer_name' => 'Lola Walk-in', 'new_customer_phone' => '0917 555 0101',
            'package' => $this->package->id, 'custom_theme_text' => 'Plain wall', 'date' => '2026-11-05', 'time' => '14:00',
            'group_size' => 2, 'confirmed_price' => '1500',
        ], $overrides));
    }

    private function freeTimes(string $date): array
    {
        return app(AvailabilityService::class)->slots(60, $date, $date)->map(fn ($slot) => $slot->startsAt->copy()->setTimezone('Asia/Manila')->format('H:i'))->all();
    }

    private function order(string $price, array $overrides = [])
    {
        $service = PrintingService::factory()->published()->create(['base_price' => '1.00', 'allowed_file_types' => ['pdf']]);

        return $this->actingAs($this->owner)->post(route('owner.orders.walk-in.store'), array_replace([
            'counter_token' => (string) Str::uuid(), 'new_customer_name' => 'Lola Walk-in', 'service' => $service->id, 'quantity' => 1,
            'price_choice' => 'custom', 'custom_price' => $price, 'paid_now' => '0',
        ], $overrides));
    }

    // 3a, 3b, 4b: the schedule and the downpayment

    public function test_an_unpaid_walk_in_booking_awaits_its_downpayment_holds_its_time_and_is_cancelled_at_the_deadline(): void
    {
        $this->assertContains('14:00', $this->freeTimes('2026-11-05'));

        $this->book()->assertSessionHasNoErrors()->assertSessionHas('status');
        $booking = PhotographyBooking::query()->sole();

        // Shoot Thu 14:00, now Mon 08:00: 3 days before is Mon 14:00, at least 24 hours after now: Tue 08:00
        $this->assertSame(BookingStatus::AwaitingDownpayment, $booking->status);
        $this->assertTrue($booking->downpayment_due_at->eq($this->manila('2026-11-03 08:00')));
        $this->assertStringContainsString('Downpayment of ₱750.00 due by', $booking->statusHistories()->sole()->note);

        // Its time and the 30-minute buffer are not offered online, and it counts toward the daily limit
        $free = $this->freeTimes('2026-11-05');
        $this->assertNotContains('14:00', $free);
        $this->assertNotContains('13:00', $free);
        $this->assertNotContains('15:00', $free);
        $this->assertTrue(PhotographyBooking::query()->holdingTime()->whereKey($booking->id)->exists());

        // Past the deadline the system cancels it like an online booking, and the time opens again
        $this->travelTo($this->manila('2026-11-03 08:01'));
        $this->assertSame(1, app(DownpaymentExpiryService::class)->expireDue());
        $this->assertSame(BookingStatus::Cancelled, $booking->fresh()->status);
        $this->assertContains('14:00', $this->freeTimes('2026-11-05'));
    }

    public function test_the_downpayment_recorded_at_the_counter_confirms_it_and_a_cancel_frees_the_time(): void
    {
        $this->book()->assertSessionHasNoErrors();
        $booking = PhotographyBooking::query()->sole();

        app(PaymentService::class)->record($booking, $this->owner, PaymentMethod::Cash, null, null, PaymentKind::Downpayment);
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);

        // The owner cancels with the same keep/refund choice as online; the time opens again
        app(BookingTransitionService::class)->cancel($booking->fresh(), $this->owner, 'Customer changed plans.', BookingTransitionService::KEEP_PAYMENT);
        $this->assertSame(BookingStatus::Cancelled, $booking->fresh()->status);
        $this->assertContains('14:00', $this->freeTimes('2026-11-05'));
    }

    public function test_a_walk_in_booking_follows_every_online_rule_with_no_override(): void
    {
        foreach (['outside_schedule' => '1', 'over_daily_limit' => '1'] as $tick => $value) {
            // Today (same day), Sunday (closed), off the photoshoot hours, beyond the advance window: all refused
            foreach ([['date' => '2026-11-02', 'time' => '15:00'], ['date' => '2026-11-08'], ['time' => '16:30'], ['date' => '2026-12-21']] as $when) {
                $this->book($when + [$tick => $value])->assertSessionHasErrors('starts_at');
            }
        }
        $this->assertSame(0, PhotographyBooking::query()->count());

        // The buffer around another booking holds for walk-ins too
        PhotographyBooking::factory()->status(BookingStatus::Confirmed)->at($this->manila('2026-11-05 10:00'), 60)->create(['photography_package_id' => $this->package->id]);
        $this->book(['time' => '11:00'])->assertSessionHasErrors('starts_at');
        $this->book(['time' => '11:30', 'pay_kind' => 'full', 'pay_method' => 'cash'])->assertSessionHasNoErrors();
        $this->assertSame(BookingStatus::Confirmed, PhotographyBooking::query()->latest('id')->first()->status);
    }

    // 3c: printing

    public function test_a_walk_in_order_follows_the_pay_first_rule(): void
    {
        // ₱350 is at or above the default "Pay first from" ₱300: accepted, unpaid, not printed until paid
        $this->order('350')->assertSessionHasNoErrors();
        $order = PrintingOrder::query()->sole();
        $this->assertSame([PrintingOrderStatus::Accepted, true], [$order->status, (bool) $order->requires_prepayment]);
        $this->assertNull($order->recordedPayment);

        $transitions = app(StatusTransitionService::class);
        try {
            $transitions->transition($order, PrintingOrderStatus::Processing, $this->owner);
            $this->fail('An unpaid order that must be paid first went to printing.');
        } catch (InvalidStatusTransitionException $e) {
            $this->assertStringContainsString('Collect the payment before printing.', $e->getMessage());
        }

        app(PaymentService::class)->record($order->fresh(), $this->owner, PaymentMethod::Cash);
        $this->assertSame(PrintingOrderStatus::Processing, $transitions->transition($order->fresh(), PrintingOrderStatus::Processing, $this->owner)->status);

        // A small order may be printed before it is paid, like online
        $this->order('150')->assertSessionHasNoErrors();
        $small = PrintingOrder::query()->latest('id')->first();
        $this->assertFalse((bool) $small->requires_prepayment);
        $this->assertSame(PrintingOrderStatus::Processing, $transitions->transition($small, PrintingOrderStatus::Processing, $this->owner)->status);
    }

    public function test_paid_now_records_the_payment_and_starts_printing(): void
    {
        $this->order('350', ['paid_now' => '1', 'method' => 'cash', 'collected' => '1'])->assertSessionHasNoErrors();
        $order = PrintingOrder::query()->sole();
        $this->assertSame(PrintingOrderStatus::Processing, $order->status);
        $this->assertSame('350.00', $order->recordedPayment->amount);

        // "Paid now" needs the method and the "collected" tick
        $this->order('200', ['paid_now' => '1'])->assertSessionHasErrors(['method', 'collected']);
    }

    // 4: one phone

    public function test_one_phone_for_a_booking(): void
    {
        // A new walk-in: the mobile number is required for a booking, saved on the customer and used as the contact
        $this->book(['new_customer_phone' => ''])->assertSessionHasErrors(['new_customer_phone' => StoreWalkInBookingRequest::PHONE_REQUIRED]);
        $this->book(['new_customer_phone' => '+63 917 555 0101'])->assertSessionHasNoErrors();
        $first = PhotographyBooking::query()->sole();
        $this->assertSame(['09175550101', '09175550101'], [$first->customer->phone, $first->contact_number]);

        // A picked customer: their saved number, or a different one for this booking only
        $picked = ['new_customer_name' => '', 'new_customer_phone' => '', 'customer_id' => $first->customer_id];
        $this->book($picked + ['time' => '10:00'])->assertSessionHasNoErrors();
        $this->assertSame('09175550101', PhotographyBooking::query()->latest('id')->first()->contact_number);
        $this->book($picked + ['time' => '11:30', 'other_number' => '1', 'contact_number' => '0918 222 3333'])->assertSessionHasNoErrors();
        $this->assertSame('09182223333', PhotographyBooking::query()->latest('id')->first()->contact_number);
        $this->assertSame('09175550101', $first->customer->fresh()->phone);

        // A picked walk-in without a phone: a number is needed, and it is saved on them
        $noPhone = User::factory()->walkIn()->create(['name' => 'Nora', 'phone' => null]);
        $this->book(['new_customer_name' => '', 'new_customer_phone' => '', 'customer_id' => $noPhone->id, 'date' => '2026-11-06'])
            ->assertSessionHasErrors(['contact_number' => StoreWalkInBookingRequest::NO_PHONE]);
        $this->book(['new_customer_name' => '', 'new_customer_phone' => '', 'customer_id' => $noPhone->id, 'date' => '2026-11-06', 'contact_number' => '0919 444 5555'])->assertSessionHasNoErrors();
        $this->assertSame('09194445555', $noPhone->fresh()->phone);
    }

    public function test_the_phone_is_optional_for_a_printing_order(): void
    {
        $this->order('100', ['new_customer_phone' => ''])->assertSessionHasNoErrors();
        $this->assertNull(PrintingOrder::query()->sole()->customer->phone);
    }

    // 1: the one Walk-in area

    public function test_the_walk_in_page_lists_what_needs_a_call_and_only_walk_in_customers(): void
    {
        $this->book()->assertSessionHasNoErrors();
        $online = User::factory()->create(['name' => 'Ana Online']);
        PhotographyBooking::factory()->forCustomer($online)->status(BookingStatus::AwaitingDownpayment)->at($this->manila('2026-11-06 10:00'), 60)
            ->create(['photography_package_id' => $this->package->id, 'confirmed_price' => '1500.00', 'downpayment_due_at' => $this->manila('2026-11-04 10:00'), 'reference_number' => 'PB-777777']);

        $html = $this->actingAs($this->owner)->get(route('owner.walk-in.index'))->assertOk()
            ->assertSee('New walk-in printing order')->assertSee('New walk-in booking')->assertSee('Find a walk-in customer')
            ->assertSee('data-attention="awaiting"', false)->assertSee('tel:+639175550101', false)->assertSee('0917 555 0101')
            ->assertSee('Lola Walk-in')->assertDontSee('Ana Online')->assertDontSee('PB-777777')->getContent();
        $this->assertStringContainsString(route('owner.orders.walk-in.create'), $html);

        // Search and the typeahead's walk-in scope find walk-ins only
        $this->actingAs($this->owner)->get(route('owner.walk-in.index', ['q' => 'lola']))->assertOk()->assertSee('Lola Walk-in');
        $this->assertSame(['Lola Walk-in'], array_column($this->actingAs($this->owner)->getJson(route('owner.suggest', ['scope' => 'walk_in', 'q' => 'lo']))->json('items'), 'label'));

        // Owner only; the sidebar has the one Walk-in item and Payments no longer starts a walk-in itself
        $this->actingAs($online)->get(route('owner.walk-in.index'))->assertForbidden();
        $this->actingAs($this->owner)->get(route('owner.payments.index'))->assertOk()->assertDontSee('New walk-in')->assertSee(route('owner.walk-in.index'), false);
    }

    public function test_an_order_ready_and_unpaid_and_an_order_to_pay_first_need_attention(): void
    {
        $this->order('350')->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->get(route('owner.walk-in.index'))->assertOk()->assertSee('data-attention="prepay"', false)->assertSee('To be paid before printing');

        $this->order('150')->assertSessionHasNoErrors();
        $small = PrintingOrder::query()->latest('id')->first();
        $transitions = app(StatusTransitionService::class);
        $transitions->transition($small, PrintingOrderStatus::Processing, $this->owner);
        $transitions->transition($small->fresh(), PrintingOrderStatus::ReadyForPickup, $this->owner);

        $this->actingAs($this->owner)->get(route('owner.walk-in.index'))->assertOk()
            ->assertSee('data-attention="ready"', false)->assertSee($small->reference_number)->assertSee('waiting 0 days');
    }

    // 2: the walk-in customer page

    public function test_the_walk_in_customer_page_follows_their_records_and_edits_name_and_phone(): void
    {
        $this->book()->assertSessionHasNoErrors();
        $booking = PhotographyBooking::query()->sole();
        $customer = $booking->customer;

        $this->actingAs($this->owner)->get(route('owner.customers.show', $customer))->assertOk()
            ->assertSee('Walk-in')->assertSee($booking->reference_number)->assertSee('data-next-step', false)->assertSee('Collect the downpayment by')
            ->assertSee(route('owner.orders.walk-in.create', ['customer' => $customer->id]), false)
            ->assertSee(route('owner.bookings.walk-in.create', ['customer' => $customer->id]), false)
            ->assertSee('Edit name and phone');
        $this->actingAs($this->owner)->get(route('owner.bookings.walk-in.create', ['customer' => $customer->id]))->assertOk()->assertSee('Lola Walk-in');
        $this->actingAs($this->owner)->get(route('owner.bookings.show', $booking))->assertOk()->assertSee('Walk-in customer page')->assertSee(route('owner.customers.show', $customer), false);

        $this->actingAs($this->owner)->put(route('owner.walk-in.customers.update', $customer), ['name' => 'Lola Santos', 'phone' => 'call me'])->assertSessionHasErrorsIn('walkIn', 'phone');
        $this->actingAs($this->owner)->put(route('owner.walk-in.customers.update', $customer), ['name' => 'Lola Santos', 'phone' => '0918 111 2222'])->assertRedirect(route('owner.customers.show', $customer));
        $this->assertSame(['Lola Santos', '09181112222'], [$customer->fresh()->name, $customer->fresh()->phone]);
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::WalkInCustomerUpdated)->count());
        $this->assertStringNotContainsString('0918', (string) json_encode(AuditLog::query()->where('action', AuditAction::WalkInCustomerUpdated)->sole()->meta));

        // An online customer is not edited here
        $online = User::factory()->create();
        $this->actingAs($this->owner)->put(route('owner.walk-in.customers.update', $online), ['name' => 'Someone Else'])->assertNotFound();
        $this->actingAs($online)->put(route('owner.walk-in.customers.update', $customer), ['name' => 'Hacker'])->assertForbidden();
    }

    // 4b: the schedule shows walk-ins

    public function test_the_schedule_shows_the_walk_in_booking_with_its_badge(): void
    {
        $this->book()->assertSessionHasNoErrors();
        $booking = PhotographyBooking::query()->sole();

        $this->actingAs($this->owner)->get(route('owner.schedule.index', ['view' => 'week', 'date' => '2026-11-05']))->assertOk()
            ->assertSee($booking->reference_number)->assertSee('Walk-in')->assertSee(route('owner.bookings.show', $booking), false);
        $this->actingAs($this->owner)->get(route('owner.bookings.index', ['status' => 'all', 'source' => 'walk_in']))->assertOk()->assertSee($booking->reference_number);

        // Its walk-in is never notified
        $this->assertSame(0, DB::table('notifications')->count());
    }
}
