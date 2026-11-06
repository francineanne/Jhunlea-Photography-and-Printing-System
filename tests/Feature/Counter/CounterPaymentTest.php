<?php

namespace Tests\Feature\Counter;

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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Phase 8B: the counter on the Payments page (search, record payment, receipt, queues, the ledger's day view). */
class CounterPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->owner()->create(['name' => 'Jhun Owner']);
        $this->customer = User::factory()->create(['name' => 'Ana Reyes', 'phone' => '09171234567']);
    }

    private function order(array $attributes = [], Status $status = Status::Accepted): PrintingOrder
    {
        return PrintingOrder::factory()->status($status)->forCustomer($this->customer)->create($attributes + ['confirmed_price' => '1850.00']);
    }

    private function pay(PrintingOrder|PhotographyBooking $record, array $form = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)->postJson(route('owner.payments.counter.record'), $form + [
            'payable_type' => $record->getMorphClass(),
            'payable_id' => $record->id,
            'method' => 'cash',
            'expected_amount' => (string) $record->confirmed_price,
            'collected' => '1',
        ]);
    }

    public function test_the_counter_records_the_full_price_and_cash_received_only_works_out_the_change(): void
    {
        $order = $this->order();

        $this->pay($order, ['cash_tendered' => '2000'])->assertOk()
            ->assertJson(['reference' => 'PAY-000001', 'amount' => '₱1,850.00', 'change' => '₱150.00', 'for' => $order->reference_number])
            ->assertJsonPath('receipt_url', route('owner.payments.receipt', Payment::query()->first()));

        $payment = Payment::query()->sole();
        $this->assertSame('1850.00', (string) $payment->amount);
        $this->assertSame($this->owner->id, $payment->received_by);
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('payments', 'cash_tendered'));
        $this->assertStringNotContainsString('2000', json_encode($payment->getAttributes()));
    }

    public function test_a_double_submit_saves_one_payment(): void
    {
        $order = $this->order();

        $this->pay($order)->assertOk();
        $this->pay($order)->assertStatus(422)->assertJsonPath('errors.payment.0', fn ($message) => str_starts_with($message, 'Already paid'));
        $this->assertSame(1, Payment::query()->count());

        // Without JavaScript the same POST redirects back with the error, still one payment
        $this->actingAs($this->owner)->post(route('owner.payments.counter.record'), [
            'payable_type' => 'printing_order', 'payable_id' => $order->id, 'method' => 'cash', 'expected_amount' => '1850.00', 'collected' => '1',
        ])->assertRedirect(route('owner.payments.index'))->assertSessionHas('error');
        $this->assertSame(1, Payment::query()->count());
    }

    public function test_the_modal_rules(): void
    {
        $order = $this->order();

        // Required tick, GCash reference, a stale amount, cash below the full price
        $this->pay($order, ['collected' => '0'])->assertStatus(422)->assertJsonValidationErrors('collected');
        $this->pay($order, ['method' => 'gcash'])->assertStatus(422)->assertJsonValidationErrors('gcash_reference');
        $this->pay($order, ['method' => 'card'])->assertStatus(422)->assertJsonValidationErrors('method');
        $this->pay($order, ['expected_amount' => '1000.00'])->assertStatus(422);
        $this->pay($order, ['cash_tendered' => '1849.99'])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'less than the full amount'));
        $this->assertSame(0, Payment::query()->count());

        // Targets that cannot be selected, each with its plain reason
        $noPrice = $this->order(['confirmed_price' => null], Status::UnderReview);
        $cancelled = $this->order([], Status::Cancelled);
        $free = $this->order(['confirmed_price' => '0.00']);
        foreach ([[$noPrice, 'No confirmed price yet'], [$cancelled, 'cancelled'], [$free, 'Nothing to pay']] as [$record, $reason]) {
            $this->actingAs($this->owner)->getJson(route('owner.payments.counter.target', ['type' => 'printing_order', 'id' => $record->id]))
                ->assertOk()->assertJsonPath('target.payable', false)->assertJsonPath('target.blocker', fn ($text) => str_contains($text, $reason));
            $this->pay($record, ['expected_amount' => '0.00'])->assertStatus(422);
        }

        // A voided payment makes the order payable again (Phase 5 rule), with a new PAY number
        $voided = $this->order(['confirmed_price' => '300.00']);
        $service = app(PaymentService::class);
        $service->void($voided, $service->record($voided, $this->owner, PaymentMethod::Cash), $this->owner, 'Wrong order.');
        $this->actingAs($this->owner)->getJson(route('owner.payments.counter.target', ['type' => 'printing_order', 'id' => $voided->id]))->assertJsonPath('target.payable', true);
        $this->pay($voided, ['method' => 'gcash', 'gcash_reference' => 'GC-778899'])->assertOk();

        $paid = $voided->fresh();
        $this->actingAs($this->owner)->getJson(route('owner.payments.counter.target', ['type' => 'printing_order', 'id' => $paid->id]))
            ->assertJsonPath('target.blocker', fn ($text) => str_starts_with($text, 'Already paid'));
        $this->actingAs($this->owner)->getJson(route('owner.payments.counter.target', ['type' => 'printing_order', 'id' => 999]))->assertNotFound();
    }

    public function test_search_by_name_phone_and_reference_with_wildcards_taken_literally(): void
    {
        $order = $this->order();
        $walkIn = User::factory()->walkIn()->create(['name' => 'Ben Walk', 'phone' => '09990001111']);
        $booking = PhotographyBooking::factory()->walkIn()->status(BookingStatus::Confirmed)->forCustomer($walkIn)->create(['confirmed_price' => '2500.00']);
        $digits = substr($order->reference_number, 3);

        $find = fn (string $q) => collect($this->actingAs($this->owner)->getJson(route('owner.payments.counter.search', ['q' => $q]))->assertOk()->json('results'))->pluck('reference')->all();

        $this->assertSame([$order->reference_number], $find('Ana Rey'));
        $this->assertSame([$order->reference_number], $find('0917123'));
        $this->assertSame([$booking->reference_number], $find('Ben Walk'));
        $this->assertSame([$booking->reference_number], $find('0999000'));
        $this->assertSame([$order->reference_number], $find('pr'.$digits));
        $this->assertSame([$booking->reference_number], $find($booking->reference_number));
        $this->assertSame([], $find('%%'));
        $this->assertSame([], $find('__'));

        $result = $this->actingAs($this->owner)->getJson(route('owner.payments.counter.search', ['q' => 'Ben']))->json('results.0');
        $this->assertTrue($result['walk_in']);
        $this->assertSame('₱2,500.00', $result['price_text']);
        $this->assertSame('Unpaid', $result['payment']);
    }

    public function test_a_walk_in_booking_is_paid_on_the_day(): void
    {
        $walkIn = User::factory()->walkIn()->create(['name' => 'Ben Walk']);
        $booking = PhotographyBooking::factory()->walkIn()->status(BookingStatus::Confirmed)->forCustomer($walkIn)
            ->at(Carbon::now('UTC')->subHour())->create(['confirmed_price' => '2500.00']);

        $this->pay($booking, ['method' => 'gcash', 'gcash_reference' => 'gc-walk-01'])->assertOk()->assertJsonPath('change', null);
        $payment = $booking->fresh()->recordedPayment;
        $this->assertSame('2500.00', (string) $payment->amount);
        $this->assertSame('GC-WALK-01', $payment->gcash_reference);
        $this->actingAs($this->owner)->post(route('owner.bookings.complete', $booking))->assertSessionHas('status');
        $this->assertSame(BookingStatus::Completed, $booking->fresh()->status);
    }

    public function test_the_receipt_is_owner_only_and_says_it_is_not_official(): void
    {
        $order = $this->order();
        $payment = app(PaymentService::class)->record($order, $this->owner, PaymentMethod::Gcash, 'GC-RECEIPT-1');

        $this->actingAs($this->owner)->get(route('owner.payments.receipt', $payment))->assertOk()
            ->assertSee('Payment receipt')->assertSee('This is not an official receipt.')->assertSee('PAY-000001')
            ->assertSee($order->reference_number)->assertSee('₱1,850.00')->assertSee('GC-RECEIPT-1')->assertSee('Jhun Owner')->assertSee('Ana Reyes')
            ->assertDontSee('>VOID<', false)->assertDontSee('cdn', false)->assertDontSee('fonts.googleapis', false);

        $this->actingAs($this->customer)->get(route('owner.payments.receipt', $payment))->assertForbidden();
        auth()->logout();
        $this->get(route('owner.payments.receipt', $payment))->assertRedirect(route('login'));
        $this->actingAs($this->owner)->get(route('owner.payments.receipt', 999))->assertNotFound();

        app(PaymentService::class)->void($order, $payment, $this->owner, 'Typed the wrong order.');
        $this->actingAs($this->owner)->get(route('owner.payments.receipt', $payment))->assertOk()
            ->assertSee('>VOID<', false)->assertSee('Typed the wrong order.')->assertSee('It does not count as a payment.');
    }

    public function test_customers_and_guests_cannot_use_the_counter(): void
    {
        $order = $this->order();

        $this->actingAs($this->customer)->getJson(route('owner.payments.counter.search', ['q' => 'Ana']))->assertForbidden();
        $this->actingAs($this->customer)->getJson(route('owner.payments.counter.target', ['type' => 'printing_order', 'id' => $order->id]))->assertForbidden();
        $this->pay($order, [], $this->customer)->assertForbidden();
        auth()->logout();
        $this->getJson(route('owner.payments.counter.search', ['q' => 'Ana']))->assertUnauthorized();
        $this->postJson(route('owner.payments.counter.record'), [])->assertUnauthorized();
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_the_queues_show_the_right_rows_and_counts_and_release_keeps_the_gate(): void
    {
        $unpaidReady = $this->order(['confirmed_price' => '120.00'], Status::ReadyForPickup);
        $paidReady = $this->order(['confirmed_price' => '80.00'], Status::ReadyForPickup);
        app(PaymentService::class)->record($paidReady, $this->owner, PaymentMethod::Cash);
        $old = $this->order(['confirmed_price' => '60.00', 'created_at' => Carbon::now('UTC')->subDays(4)], Status::Processing);
        $booking = PhotographyBooking::factory()->status(BookingStatus::Confirmed)->forCustomer($this->customer)->create(['confirmed_price' => '900.00']);
        $this->order(['confirmed_price' => null], Status::UnderReview);

        $response = $this->actingAs($this->owner)->get(route('owner.payments.index'))->assertOk()
            ->assertViewHas('readyCount', 2)->assertViewHas('awaitingCount', 3)
            ->assertViewHas('ready', fn ($page) => $page->pluck('id')->sort()->values()->all() === collect([$unpaidReady->id, $paidReady->id])->sort()->values()->all())
            ->assertViewHas('awaitingQueue', fn ($page) => $page->first()->is($old) && $page->total() === 3
                && $page->contains(fn ($row) => $row->is($booking)) && $page->contains(fn ($row) => $row->is($unpaidReady)))
            ->assertSee('Ready for pickup')->assertSee('Waiting 4 days')->assertSee('Release (completed)');
        $response->assertSee('pay=printing_order%3A'.$unpaidReady->id, false);

        // Release from the queue: paid goes to Completed and back to Payments; unpaid is refused by the unchanged gate
        $this->actingAs($this->owner)->post(route('owner.orders.complete', $paidReady), ['return' => 'payments'])
            ->assertRedirect(route('owner.payments.index').'#ready-title')->assertSessionHas('status');
        $this->assertSame(Status::Completed, $paidReady->fresh()->status);
        $this->actingAs($this->owner)->post(route('owner.orders.complete', $unpaidReady), ['return' => 'payments'])
            ->assertSessionHas('error', 'Record the payment first. Photos and orders are released only when fully paid.');
        $this->assertSame(Status::ReadyForPickup, $unpaidReady->fresh()->status);
        // Any other return value keeps the usual redirect
        $this->actingAs($this->owner)->post(route('owner.orders.complete', $unpaidReady), ['return' => 'https://evil.test'])
            ->assertRedirect(route('owner.orders.show', $unpaidReady).'#actions');
    }

    public function test_the_queries_do_not_grow_with_the_rows(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->owner)->get(route('owner.payments.index'))->assertOk();

            return count(DB::getQueryLog());
        };

        foreach (range(1, 2) as $i) {
            $this->order(['confirmed_price' => '10.00'], Status::ReadyForPickup);
            PhotographyBooking::factory()->status(BookingStatus::Confirmed)->forCustomer($this->customer)->create(['confirmed_price' => '20.00']);
            app(PaymentService::class)->record($this->order(), $this->owner, PaymentMethod::Cash);
        }
        $few = $count();

        foreach (range(1, 6) as $i) {
            $this->order(['confirmed_price' => '10.00'], Status::ReadyForPickup);
            PhotographyBooking::factory()->status(BookingStatus::Confirmed)->forCustomer($this->customer)->create(['confirmed_price' => '20.00']);
            app(PaymentService::class)->record($this->order(), $this->owner, PaymentMethod::Cash);
        }

        $this->assertSame($few, $count());
    }

    public function test_the_ledger_shows_today_by_default_with_the_same_totals(): void
    {
        $service = app(PaymentService::class);
        $today = $service->record($this->order(['confirmed_price' => '100.00']), $this->owner, PaymentMethod::Cash);
        $yesterday = Payment::factory()->paying($this->order(['confirmed_price' => '40.00']))
            ->create(['amount' => '40.00', 'received_at' => Carbon::now('UTC')->subDay(), 'received_by' => $this->owner->id]);
        $voidMe = $this->order(['confirmed_price' => '999.00']);
        $service->void($voidMe, $service->record($voidMe, $this->owner, PaymentMethod::Cash), $this->owner, 'Mistake.');

        $this->actingAs($this->owner)->get(route('owner.payments.index'))->assertOk()
            ->assertViewHas('range', 'today')
            ->assertViewHas('payments', fn ($page) => $page->total() === 2 && ! $page->pluck('id')->contains($yesterday->id))
            ->assertViewHas('totals', fn ($totals) => $totals['cents'] === 10000 && $totals['count'] === 1)
            ->assertSee('Print receipt')->assertSee('Online');

        $this->actingAs($this->owner)->get(route('owner.payments.index', ['range' => 'yesterday']))
            ->assertViewHas('payments', fn ($page) => $page->pluck('id')->all() === [$yesterday->id])
            ->assertViewHas('totals', fn ($totals) => $totals['cents'] === 4000);

        $this->actingAs($this->owner)->get(route('owner.payments.index', ['range' => 'all']))
            ->assertViewHas('totals', fn ($totals) => $totals['cents'] === 14000 && $totals['count'] === 2);

        // The filter form of the default view carries no range, so a search from it covers every day
        $this->actingAs($this->owner)->get(route('owner.payments.index'))->assertDontSee('name="range" value="today"', false);
        $this->actingAs($this->owner)->get(route('owner.payments.index', ['range' => 'yesterday']))->assertSee('name="range" value="yesterday"', false);
        $this->actingAs($this->owner)->get(route('owner.payments.index', ['q' => $yesterday->reference_number]))
            ->assertViewHas('payments', fn ($page) => $page->pluck('id')->all() === [$yesterday->id]);

        $this->actingAs($this->owner)->get(route('owner.payments.index', ['range' => 'week']))->assertRedirect(route('owner.payments.index'))->assertSessionHasErrors('range');
        $this->assertTrue($today->isRecorded());
    }

    public function test_a_day_without_payments_says_so(): void
    {
        Payment::factory()->paying($this->order())->create(['received_at' => Carbon::now('UTC')->subDays(3), 'received_by' => $this->owner->id]);

        $this->actingAs($this->owner)->get(route('owner.payments.index'))->assertSee('No payments recorded today')->assertDontSee('No payments recorded yet');
    }

    public function test_the_pay_link_opens_the_modal_on_that_record(): void
    {
        $order = $this->order();

        $this->actingAs($this->owner)->get(route('owner.payments.index', ['pay' => 'printing_order:'.$order->id]))
            ->assertViewHas('pay', ['type' => 'printing_order', 'id' => $order->id]);
        $this->actingAs($this->owner)->get(route('owner.payments.index', ['pay' => 'users:1']))->assertViewHas('pay', null);
        $this->actingAs($this->owner)->get(route('owner.orders.show', $order))->assertSee('Open at the counter');
    }
}
