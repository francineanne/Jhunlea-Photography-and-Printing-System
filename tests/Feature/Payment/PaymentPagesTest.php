<?php

namespace Tests\Feature\Payment;

use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Enums\PrintingOrderStatus as Status;
use App\Models\Payment;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Phase 5A: recording and voiding through the pages, what customers see, and the owner's ledger. */
class PaymentPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->owner()->create(['name' => 'Jhun Owner']);
        $this->customer = User::factory()->create(['name' => 'Ana Reyes']);
    }

    private function order(array $attributes = []): PrintingOrder
    {
        return PrintingOrder::factory()->status(Status::Accepted)->forCustomer($this->customer)->create($attributes + ['confirmed_price' => '1850.00']);
    }

    private function record(PrintingOrder $order, array $form = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)->from(route('owner.orders.show', $order))
            ->post(route('owner.orders.payments.store', $order), $form + ['method' => 'cash', 'confirm' => '1', 'expected_amount' => $order->confirmed_price]);
    }

    public function test_the_owner_records_a_payment_and_a_posted_amount_is_ignored(): void
    {
        $order = $this->order();

        $this->record($order, ['amount' => '1.00', 'received_by' => $this->customer->id, 'status' => 'voided', 'reference_number' => 'PAY-999999'])
            ->assertRedirect(route('owner.orders.show', $order).'#payment')
            ->assertSessionHas('status', 'Payment PAY-000001 recorded: ₱1,850.00 by Cash.');

        $payment = Payment::sole();
        $this->assertSame('1850.00', $payment->amount);
        $this->assertSame($this->owner->id, $payment->received_by);
        $this->assertTrue($payment->isRecorded());
        $this->assertSame('PAY-000001', $payment->reference_number);
    }

    public function test_the_form_is_validated(): void
    {
        $order = $this->order();

        $this->record($order, ['confirm' => null])->assertSessionHasErrors(['confirm' => 'Tick the box to confirm you received the full amount.']);
        $this->record($order, ['method' => 'card'])->assertSessionHasErrors('method');
        $this->record($order, ['method' => 'gcash'])->assertSessionHasErrors(['gcash_reference' => 'Enter the GCash reference number from the customer’s receipt.']);
        $this->record($order, ['method' => 'gcash', 'gcash_reference' => '<b>12'])->assertSessionHasErrors('gcash_reference');
        $this->record($order, ['method' => ['cash']])->assertSessionHasErrors('method');
        $this->record($order, ['expected_amount' => '1,850'])->assertSessionHasErrors('expected_amount');
        $this->assertSame(0, Payment::count());
    }

    public function test_the_page_still_renders_after_a_hostile_form(): void
    {
        $order = $this->order();

        $this->record($order, ['method' => ['gcash'], 'gcash_reference' => ['x'], '_form' => 'payment'])->assertSessionHasErrors(['method', 'gcash_reference']);
        $this->actingAs($this->owner)->get(route('owner.orders.show', $order))->assertOk()
            ->assertSee("method: 'cash'", false)->assertSee("reference: ''", false);
    }

    public function test_a_double_submit_saves_one_payment_and_explains_the_second(): void
    {
        $order = $this->order();

        $this->record($order)->assertSessionHas('status');
        $this->record($order)->assertSessionHas('error', 'A payment is already recorded for this. Nothing new was saved.');
        $this->assertSame(1, Payment::count());
    }

    public function test_a_stale_page_saves_nothing(): void
    {
        $order = $this->order();

        $this->record($order, ['expected_amount' => '1500.00'])->assertSessionHas('error');
        $this->assertSame(0, Payment::count());
    }

    public function test_customers_and_guests_cannot_record_void_or_open_the_ledger(): void
    {
        $order = $this->order();
        $payment = app(PaymentService::class)->record($order, $this->owner, PaymentMethod::Cash);
        $booking = PhotographyBooking::factory()->status(BookingStatus::Confirmed)->forCustomer($this->customer)->create(['confirmed_price' => '900.00']);

        foreach ([$this->customer, User::factory()->create()] as $user) {
            $this->actingAs($user)->post(route('owner.orders.payments.store', $order), ['method' => 'cash', 'confirm' => '1'])->assertForbidden();
            $this->actingAs($user)->post(route('owner.orders.payments.void', [$order, $payment]), ['reason' => 'x'])->assertForbidden();
            $this->actingAs($user)->post(route('owner.bookings.payments.store', $booking), ['method' => 'cash', 'confirm' => '1'])->assertForbidden();
            $this->actingAs($user)->get(route('owner.payments.index'))->assertForbidden();
        }

        auth()->logout();
        $this->post(route('owner.orders.payments.store', $order), ['method' => 'cash', 'confirm' => '1'])->assertRedirect(route('login'));
        $this->post(route('owner.orders.payments.void', [$order, $payment]), ['reason' => 'x'])->assertRedirect(route('login'));
        $this->get(route('owner.payments.index'))->assertRedirect(route('login'));

        $this->assertSame(1, Payment::count());
        $this->assertTrue($payment->fresh()->isRecorded());
    }

    public function test_the_owner_voids_with_a_reason(): void
    {
        $order = $this->order();
        $payment = app(PaymentService::class)->record($order, $this->owner, PaymentMethod::Cash);

        $this->actingAs($this->owner)->post(route('owner.orders.payments.void', [$order, $payment]), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($this->owner)->post(route('owner.orders.payments.void', [$order, $payment]), ['reason' => str_repeat('x', 501)])->assertSessionHasErrors('reason');

        $this->actingAs($this->owner)->post(route('owner.orders.payments.void', [$order, $payment]), ['reason' => 'Recorded twice by mistake.'])
            ->assertSessionHas('status');
        $this->assertFalse($payment->fresh()->isRecorded());

        // A payment of another order through this order's address changes nothing
        $other = $this->order();
        $theirs = app(PaymentService::class)->record($other, $this->owner, PaymentMethod::Cash);
        $this->actingAs($this->owner)->post(route('owner.orders.payments.void', [$order, $theirs]), ['reason' => 'Wrong.'])->assertSessionHas('error');
        $this->assertTrue($theirs->fresh()->isRecorded());
    }

    public function test_the_customer_sees_their_payment_without_the_gcash_reference(): void
    {
        $order = $this->order();
        $this->actingAs($this->customer)->get(route('customer.printing.show', $order))
            ->assertSee('Amount to pay at the shop')->assertSee('₱1,850.00')->assertSee('Unpaid')
            ->assertSee('Nothing is charged online.')->assertDontSee('Record payment');

        app(PaymentService::class)->record($order, $this->owner, PaymentMethod::Gcash, 'GC-HIDDEN-4321');

        $this->actingAs($this->customer)->get(route('customer.printing.show', $order))
            ->assertSee('Paid')->assertSee('PAY-000001')->assertSee('GCash')->assertSee('This is a payment record, not an official receipt.')
            ->assertDontSee('GC-HIDDEN-4321')->assertDontSee('Void');

        $this->actingAs($this->owner)->get(route('owner.orders.show', $order))
            ->assertSee('GC-HIDDEN-4321')->assertSee('Recorded by')->assertSee('Jhun Owner')->assertSee('Void this payment record')
            ->assertDontSee('Change the confirmed price');
    }

    public function test_a_voided_record_shows_the_customer_only_that_it_was_cancelled(): void
    {
        $order = $this->order();
        $payment = app(PaymentService::class)->record($order, $this->owner, PaymentMethod::Cash);
        app(PaymentService::class)->void($order, $payment, $this->owner, 'Secret internal reason.');

        $this->actingAs($this->customer)->get(route('customer.printing.show', $order))
            ->assertSee('The shop cancelled this payment record. Please contact the shop.')
            ->assertSee('Payment record cancelled by the shop')
            ->assertDontSee('Secret internal reason.')->assertSee('Unpaid');

        $this->actingAs($this->owner)->get(route('owner.orders.show', $order))->assertSee('Secret internal reason.')->assertSee('Voided payment records');
    }

    public function test_customer_a_never_sees_customer_b_payment(): void
    {
        $theirs = PrintingOrder::factory()->status(Status::Accepted)->create(['confirmed_price' => '700.00']);
        app(PaymentService::class)->record($theirs, $this->owner, PaymentMethod::Gcash, 'GC-OTHER-111');

        $this->actingAs($this->customer)->get(route('customer.printing.show', $theirs))->assertForbidden();
        $this->actingAs($this->customer)->get(route('customer.printing.index'))->assertDontSee($theirs->reference_number)->assertDontSee('PAY-000001');
    }

    public function test_the_booking_pages_and_awaiting_price_wording(): void
    {
        $booking = PhotographyBooking::factory()->status(BookingStatus::Requested)->forCustomer($this->customer)->create(['confirmed_price' => null]);
        $this->actingAs($this->customer)->get(route('customer.bookings.show', $booking))
            ->assertSee('Amount to pay at the studio')->assertSee('The studio will confirm the price first.')->assertSee('Awaiting price');
        $this->actingAs($this->owner)->get(route('owner.bookings.show', $booking))->assertDontSee('Record payment');

        $confirmed = PhotographyBooking::factory()->status(BookingStatus::Confirmed)->forCustomer($this->customer)->create(['confirmed_price' => '2500.00']);
        $this->actingAs($this->owner)->get(route('owner.bookings.show', $confirmed))->assertSee('Record payment')->assertSee('Confirm you received');
        $this->actingAs($this->owner)->post(route('owner.bookings.payments.store', $confirmed), ['method' => 'gcash', 'gcash_reference' => 'GC-55667788', 'confirm' => '1', 'expected_amount' => '2500.00'])
            ->assertRedirect(route('owner.bookings.show', $confirmed).'#payment')->assertSessionHas('status');
        $this->actingAs($this->customer)->get(route('customer.bookings.show', $confirmed))->assertSee('Paid')->assertSee('PAY-000001')->assertDontSee('GC-55667788');
    }

    public function test_payment_badges_on_the_lists(): void
    {
        $paid = $this->order();
        app(PaymentService::class)->record($paid, $this->owner, PaymentMethod::Cash);
        $this->order();
        PrintingOrder::factory()->status(Status::Cancelled)->forCustomer($this->customer)->create(['confirmed_price' => '100.00']);

        $html = $this->actingAs($this->owner)->get(route('owner.orders.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Payment: </span>Paid', $html);
        $this->assertStringContainsString('Payment: </span>Unpaid', $html);

        $this->actingAs($this->customer)->get(route('customer.printing.index'))->assertSee('Payment: </span>Paid', false);
        $this->actingAs($this->owner)->get(route('owner.bookings.index'))->assertOk();
        $this->actingAs($this->customer)->get(route('customer.bookings.index'))->assertOk();
    }

    public function test_completing_an_unpaid_order_from_the_page_shows_the_rule(): void
    {
        $order = $this->order(['status' => Status::ReadyForPickup]);

        $this->actingAs($this->owner)->post(route('owner.orders.complete', $order))
            ->assertSessionHas('error', 'Record the payment first. Photos and orders are released only when fully paid.');
        $this->assertSame(Status::ReadyForPickup, $order->fresh()->status);

        $booking = PhotographyBooking::factory()->status(BookingStatus::Confirmed)->at(Carbon::now('UTC')->subHours(2))->create(['confirmed_price' => '900.00']);
        $this->actingAs($this->owner)->post(route('owner.bookings.complete', $booking))
            ->assertSessionHas('error', 'Record the payment first. Photos and orders are released only when fully paid.');
    }

    public function test_the_ledger_lists_totals_and_awaiting_payments(): void
    {
        $service = app(PaymentService::class);
        $service->record($this->order(['confirmed_price' => '100.25']), $this->owner, PaymentMethod::Cash);
        $service->record($this->order(['confirmed_price' => '200.50']), $this->owner, PaymentMethod::Gcash, 'GC-100200');
        $voidMe = $this->order(['confirmed_price' => '999.99']);
        $service->void($voidMe, $service->record($voidMe, $this->owner, PaymentMethod::Cash), $this->owner, 'Mistake.');
        $waiting = $this->order(['confirmed_price' => '50.00']);
        $booking = PhotographyBooking::factory()->status(BookingStatus::Confirmed)->forCustomer($this->customer)->create(['confirmed_price' => '1000.00']);
        PrintingOrder::factory()->status(Status::Completed)->create(['confirmed_price' => '0.00']);

        $response = $this->actingAs($this->owner)->get(route('owner.payments.index'))->assertOk()
            ->assertSee('Recorded payments')->assertDontSee('Revenue')->assertDontSee('Sales');

        // Hand calculation: 100.25 + 200.50 recorded; the voided 999.99 never counts; 50 + 999.99 (voided, unpaid again) + 1000 awaiting
        $response->assertViewHas('totals', fn ($totals) => $totals['cents'] === 30075 && $totals['count'] === 2
            && $totals['methods']['cash']['cents'] === 10025 && $totals['methods']['gcash']['cents'] === 20050);
        $response->assertViewHas('awaiting', fn ($awaiting) => $awaiting['count'] === 3 && $awaiting['cents'] === 5000 + 99999 + 100000
            && $awaiting['orders']->pluck('id')->contains($waiting->id) && $awaiting['bookings']->pluck('id')->all() === [$booking->id]);
        $response->assertSee('₱300.75')->assertSee('PAY-000003')->assertSee($waiting->reference_number);

        $this->actingAs($this->owner)->get(route('owner.payments.index', ['status' => 'voided']))
            ->assertViewHas('payments', fn ($payments) => $payments->total() === 1)
            ->assertViewHas('totals', fn ($totals) => $totals['cents'] === 30075);

        $this->actingAs($this->owner)->get(route('owner.payments.index', ['method' => 'gcash']))
            ->assertViewHas('payments', fn ($payments) => $payments->total() === 1)
            ->assertViewHas('totals', fn ($totals) => $totals['cents'] === 20050);
    }

    public function test_the_ledger_dates_are_manila_calendar_days(): void
    {
        $order = $this->order();
        // 16:30 UTC on 4 November is 00:30 on 5 November in Manila
        Payment::factory()->paying($order)->create(['received_at' => Carbon::parse('2026-11-04 16:30:00', 'UTC'), 'received_by' => $this->owner->id]);

        $this->actingAs($this->owner)->get(route('owner.payments.index', ['from' => '2026-11-05', 'to' => '2026-11-05']))
            ->assertViewHas('payments', fn ($payments) => $payments->total() === 1);
        $this->actingAs($this->owner)->get(route('owner.payments.index', ['from' => '2026-11-04', 'to' => '2026-11-04']))
            ->assertViewHas('payments', fn ($payments) => $payments->total() === 0)
            ->assertViewHas('totals', fn ($totals) => $totals['cents'] === 0);
    }

    public function test_the_ledger_search_and_hostile_filters(): void
    {
        $order = $this->order();
        app(PaymentService::class)->record($order, $this->owner, PaymentMethod::Gcash, 'GC-SEARCH-42');
        $other = PrintingOrder::factory()->status(Status::Accepted)->create(['confirmed_price' => '10.00']);
        app(PaymentService::class)->record($other, $this->owner, PaymentMethod::Cash);

        foreach (['PAY-000001', 'search-42', $order->reference_number, 'Ana Rey'] as $term) {
            $this->actingAs($this->owner)->get(route('owner.payments.index', ['q' => $term]))
                ->assertViewHas('payments', fn ($payments) => $payments->pluck('id')->all() === [Payment::query()->where('payable_id', $order->id)->value('id')]);
        }

        foreach (['%', '_', '\\'] as $wildcard) {
            $this->actingAs($this->owner)->get(route('owner.payments.index', ['q' => $wildcard]))->assertOk()
                ->assertViewHas('payments', fn ($payments) => $payments->total() === 0);
        }

        foreach ([['method' => 'card'], ['status' => 'refunded'], ['from' => '2026-02-30'], ['from' => '2026-11-05', 'to' => '2026-11-01'], ['q' => str_repeat('a', 61)], ['q' => ['x']], ['method' => ['cash']]] as $query) {
            $this->actingAs($this->owner)->get(route('owner.payments.index', $query))->assertRedirect(route('owner.payments.index'))->assertSessionHasErrors();
        }
    }

    public function test_every_text_is_escaped(): void
    {
        $this->customer->forceFill(['name' => '<script>alert(1)</script>'])->save();
        $order = $this->order();
        $payment = app(PaymentService::class)->record($order, $this->owner, PaymentMethod::Cash);
        app(PaymentService::class)->void($order, $payment, $this->owner, '<img src=x onerror=alert(2)>');

        $this->actingAs($this->owner)->get(route('owner.payments.index'))->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
        $this->actingAs($this->owner)->get(route('owner.orders.show', $order))->assertDontSee('<img src=x onerror=alert(2)>', false);
    }

    public function test_the_ledger_is_empty_and_paginates(): void
    {
        $this->actingAs($this->owner)->get(route('owner.payments.index'))->assertSee('No payments recorded yet')->assertSee('Nothing is waiting for payment.');

        foreach (range(1, 21) as $i) {
            app(PaymentService::class)->record($this->order(), $this->owner, PaymentMethod::Cash);
        }

        $this->actingAs($this->owner)->get(route('owner.payments.index'))->assertViewHas('payments', fn ($payments) => $payments->count() === 20 && $payments->total() === 21);
        $this->actingAs($this->owner)->get(route('owner.payments.index', ['page' => 2]))->assertSee('PAY-000001');
    }
}
