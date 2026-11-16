<?php

namespace Tests\Feature\Printing;

use App\Enums\AuditAction;
use App\Enums\NotificationKind;
use App\Enums\PaymentMethod;
use App\Enums\PrintingOrderStatus as Status;
use App\Exceptions\InvalidStatusTransitionException;
use App\Models\AuditLog;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\PrintingOrder;
use App\Models\User;
use App\Services\PaymentLedgerService;
use App\Services\PaymentService;
use App\Services\StatusTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Tests\Support\SetsPaymentRules;
use Tests\TestCase;

/**
 * Phase 10B, printing: orders from "Pay first from" (₱300.00) are paid before printing, a customer may back out of an
 * accepted order while nothing is paid, and an unpaid order left at the counter "Unclaimed after days" (7) may be
 * closed as Unclaimed, after which that customer pays first.
 */
class PrepaymentTest extends TestCase
{
    use RefreshDatabase, SetsPaymentRules;

    private User $owner;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-02 00:00:00', 'UTC'));
        $this->owner = User::factory()->owner()->create();
        $this->customer = User::factory()->create();
    }

    private function service(): StatusTransitionService
    {
        return app(StatusTransitionService::class);
    }

    private function accepted(string $price, ?bool $prepay = null, ?User $customer = null): PrintingOrder
    {
        $order = PrintingOrder::factory()->forCustomer($customer ?? $this->customer)->status(Status::UnderReview)->create();

        return $this->service()->transition($order, Status::Accepted, $this->owner, null, $price, ['prepay' => $prepay]);
    }

    private function refused(callable $action): string
    {
        try {
            $action();
        } catch (InvalidStatusTransitionException $e) {
            return $e->getMessage();
        }

        $this->fail('The move was not refused.');
    }

    private function readyDaysAgo(PrintingOrder $order, int $days): PrintingOrder
    {
        $order->forceFill(['status' => Status::ReadyForPickup])->save();
        OrderStatusHistory::query()->forceCreate(['printing_order_id' => $order->id, 'from_status' => Status::Processing, 'to_status' => Status::ReadyForPickup, 'changed_by' => $this->owner->id, 'created_at' => now()->subDays($days)]);

        return $order;
    }

    public function test_the_threshold_the_owners_tick_and_the_flagged_customer(): void
    {
        $this->assertTrue($this->accepted('300.00')->requires_prepayment);
        $this->assertFalse($this->accepted('299.99')->requires_prepayment);
        $this->assertTrue($this->accepted('100.00', prepay: true)->requires_prepayment);
        $this->assertFalse($this->accepted('500.00', prepay: false)->requires_prepayment);

        $flagged = User::factory()->create(['prepay_required' => true]);
        $this->assertTrue($this->accepted('50.00', prepay: false, customer: $flagged)->requires_prepayment);

        $this->paymentRules(['prepay_from' => '1000.00']);
        $this->assertFalse($this->accepted('500.00')->requires_prepayment);

        // The customer is told in the one acceptance notice (the ₱300.00 order and the ₱100.00 one the owner ticked)
        $this->assertSame(2, DatabaseNotification::query()->where('type', NotificationKind::OrderPrepayRequired->value)->where('notifiable_id', $this->customer->id)->count());
        $this->assertStringContainsString('Please pay the full amount before we print it', NotificationKind::OrderPrepayRequired->message(['reference' => 'PR-000001', 'amount' => '300.00']));
    }

    public function test_an_order_paid_first_is_printed_only_once_paid(): void
    {
        $order = $this->accepted('450.00');

        $this->assertSame(InvalidStatusTransitionException::prepaymentRequired()->getMessage(), $this->refused(fn () => $this->service()->transition($order, Status::Processing, $this->owner)));
        $this->actingAs($this->owner)->post(route('owner.orders.processing', $order))->assertSessionHas('error', 'Collect the payment before printing. This order is paid before it is printed.');

        app(PaymentService::class)->record($order, $this->owner, PaymentMethod::Cash);
        $this->service()->transition($order, Status::Processing, $this->owner);
        $this->assertSame(Status::Processing, $order->fresh()->status);

        // A small order is still paid at pickup
        $small = $this->accepted('120.00');
        $this->service()->transition($small, Status::Processing, $this->owner);
        $this->assertSame(Status::Processing, $small->fresh()->status);
    }

    public function test_the_customer_may_back_out_of_an_accepted_order_until_it_is_paid(): void
    {
        $order = $this->accepted('450.00');
        $this->actingAs($this->customer)->get(route('customer.printing.show', $order))->assertOk()->assertSee('How to pay')->assertSee('before we print your order');
        $this->actingAs($this->customer)->post(route('customer.printing.cancel', $order), ['reason' => 'Too expensive.'])->assertSessionHasNoErrors();
        $this->assertSame(Status::Cancelled, $order->fresh()->status);
        $this->assertSame(1, DatabaseNotification::query()->where('type', NotificationKind::OrderCancelledByCustomer->value)->count());

        $paid = $this->accepted('450.00');
        app(PaymentService::class)->record($paid, $this->owner, PaymentMethod::Cash);
        $this->assertSame(InvalidStatusTransitionException::customerCannotCancelPaid()->getMessage(), $this->refused(fn () => $this->service()->transition($paid, Status::Cancelled, $this->customer)));
        $this->assertFalse(Gate::forUser($this->customer)->allows('cancel', $paid->fresh()));
    }

    public function test_unclaimed_needs_an_unpaid_order_that_waited_long_enough_and_flags_the_customer(): void
    {
        $walkIn = User::factory()->walkIn()->create();
        $tooSoon = $this->readyDaysAgo(PrintingOrder::factory()->forCustomer($this->customer)->create(['confirmed_price' => '200.00']), 6);
        $this->assertStringContainsString('can be marked unclaimed 7 days after', $this->refused(fn () => $this->service()->transition($tooSoon, Status::Unclaimed, $this->owner)));

        $paid = $this->readyDaysAgo(PrintingOrder::factory()->forCustomer($this->customer)->create(['confirmed_price' => '200.00']), 9);
        Payment::factory()->paying($paid)->create(['received_by' => $this->owner->id]);
        $this->assertSame(InvalidStatusTransitionException::unclaimedButPaid()->getMessage(), $this->refused(fn () => $this->service()->transition($paid, Status::Unclaimed, $this->owner)));

        $order = $this->readyDaysAgo(PrintingOrder::factory()->forCustomer($this->customer)->create(['confirmed_price' => '200.00']), 7);
        $this->actingAs($this->owner)->post(route('owner.orders.unclaimed', $order))->assertSessionHas('status');
        $this->assertSame(Status::Unclaimed, $order->fresh()->status);
        $this->assertTrue($this->customer->fresh()->prepay_required);
        $this->assertTrue(Status::Unclaimed->isClosed());
        $this->assertSame(1, DatabaseNotification::query()->where('type', NotificationKind::OrderUnclaimed->value)->where('notifiable_id', $this->customer->id)->count());
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::OrderUnclaimed)->count());
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::CustomerPrepaySet)->count());

        // Never income and never awaiting payment
        $ledger = app(PaymentLedgerService::class);
        $this->assertFalse($ledger->awaitingOrders()->whereKey($order->id)->exists());
        $this->assertSame(20000, $ledger->totals(['method' => null, 'status' => null, 'search' => '', 'from' => null, 'to' => null])['cents']);

        // A walk-in is flagged too, and never notified
        $theirs = $this->readyDaysAgo(PrintingOrder::factory()->forCustomer($walkIn)->walkIn()->create(['confirmed_price' => '90.00']), 8);
        $this->service()->transition($theirs, Status::Unclaimed, $this->owner);
        $this->assertTrue($walkIn->fresh()->prepay_required);
        $this->assertSame(0, DatabaseNotification::query()->where('notifiable_id', $walkIn->id)->count());

        // The next order of the flagged customer is paid first, whatever the owner ticks
        $this->assertTrue($this->accepted('40.00', prepay: false)->requires_prepayment);

        // The owner clears the flag, audited; the customer page shows it before
        $this->actingAs($this->owner)->get(route('owner.customers.show', $this->customer))->assertOk()->assertSee('Pays before printing.');
        $this->actingAs($this->owner)->post(route('owner.customers.prepay.clear', $this->customer), ['reason' => 'Paid the old order.'])->assertSessionHas('status');
        $this->assertFalse($this->customer->fresh()->prepay_required);
        $this->assertSame('Paid the old order.', AuditLog::query()->where('action', AuditAction::CustomerPrepayCleared)->sole()->meta['reason']);
        $this->actingAs($this->customer)->post(route('owner.customers.prepay.clear', $this->customer))->assertForbidden();
    }

    public function test_the_owner_cancel_of_a_paid_order_keeps_or_refunds(): void
    {
        $order = $this->accepted('450.00');
        app(PaymentService::class)->record($order, $this->owner, PaymentMethod::Cash);

        $this->actingAs($this->owner)->post(route('owner.orders.cancel', $order))->assertSessionHas('error', InvalidStatusTransitionException::paymentChoiceRequired()->getMessage());
        $this->actingAs($this->owner)->post(route('owner.orders.cancel', $order), ['payment_choice' => 'refund'])->assertSessionHas('error', InvalidStatusTransitionException::refundReasonRequired()->getMessage());
        $this->assertSame(Status::Accepted, $order->fresh()->status);

        $this->actingAs($this->owner)->post(route('owner.orders.cancel', $order), ['payment_choice' => 'refund', 'refund_reason' => 'Paper out of stock; cash returned.'])->assertSessionHas('status');
        $this->assertSame([Status::Cancelled, 0], [$order->fresh()->status, Payment::recordedFor($order)->count()]);
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::PaymentRefunded)->count());

        $kept = $this->accepted('450.00');
        app(PaymentService::class)->record($kept, $this->owner, PaymentMethod::Cash);
        $this->service()->transition($kept, Status::Cancelled, $this->owner, null, null, ['payment_choice' => 'keep']);
        $this->assertSame([Status::Cancelled, 1], [$kept->fresh()->status, Payment::recordedFor($kept)->count()]);
    }
}
