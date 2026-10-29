<?php

namespace Tests\Feature\Payment;

use App\Enums\BookingEvent;
use App\Enums\BookingStatus;
use App\Enums\OrderEvent;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRecordStatus;
use App\Enums\PaymentStatus;
use App\Enums\PrintingOrderStatus as Status;
use App\Exceptions\BookingTransitionException;
use App\Exceptions\InvalidStatusTransitionException;
use App\Exceptions\PaymentException;
use App\Models\Payment;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Models\User;
use App\Services\BookingTransitionService;
use App\Services\PaymentService;
use App\Services\StatusTransitionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Phase 5A: PaymentService, the derived payment status, the release gate and the price lock. */
class PaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private PaymentService $payments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->owner()->create();
        $this->payments = app(PaymentService::class);
    }

    private function order(Status $status = Status::Accepted, ?string $price = '1850.00'): PrintingOrder
    {
        return PrintingOrder::factory()->status($status)->create(['confirmed_price' => $price]);
    }

    private function booking(BookingStatus $status = BookingStatus::Confirmed, ?string $price = '2500.00', bool $past = false): PhotographyBooking
    {
        $start = $past ? Carbon::now('UTC')->subHours(3) : Carbon::now('UTC')->addDays(3);

        return PhotographyBooking::factory()->status($status)->at($start)->create(['confirmed_price' => $price]);
    }

    private function refused(callable $action, string $message): void
    {
        try {
            $action();
            $this->fail('Expected the action to be refused.');
        } catch (PaymentException|InvalidStatusTransitionException|BookingTransitionException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    public function test_the_derived_status_covers_every_case(): void
    {
        $this->assertSame(PaymentStatus::AwaitingPrice, $this->order(Status::UnderReview, null)->paymentStatus());
        $this->assertSame(PaymentStatus::NoCharge, $this->order(price: '0.00')->paymentStatus());

        $order = $this->order();
        $this->assertSame(PaymentStatus::Unpaid, $order->paymentStatus());

        $payment = $this->payments->record($order, $this->owner, PaymentMethod::Cash);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->paymentStatus());

        $this->payments->void($order, $payment, $this->owner, 'Recorded by mistake.');
        $this->assertSame(PaymentStatus::Unpaid, $order->fresh()->paymentStatus());

        $this->assertSame(PaymentStatus::Paid, PaymentStatus::derive(null, true));
        $this->assertTrue(PaymentStatus::NoCharge->isSettled());
        $this->assertFalse(PaymentStatus::Unpaid->isSettled());
        $this->assertFalse(PaymentStatus::AwaitingPrice->isSettled());
    }

    public function test_the_amount_is_always_the_confirmed_price_never_the_estimate(): void
    {
        $order = PrintingOrder::factory()->status(Status::Processing)->create(['estimated_price' => '999.00', 'confirmed_price' => '1234.50']);

        $payment = $this->payments->record($order, $this->owner, PaymentMethod::Cash);

        $this->assertSame('1234.50', $payment->fresh()->amount);
        $this->assertMatchesRegularExpression('/^PAY-\d{6}$/', $payment->reference_number);
        $this->assertSame('PAY-000001', $payment->reference_number);
        $this->assertSame($this->owner->id, $payment->received_by);
        $this->assertSame(PaymentRecordStatus::Recorded, $payment->fresh()->status);
        $this->assertSame(1, $payment->fresh()->active);
    }

    public function test_the_morph_map_stores_short_names(): void
    {
        $this->payments->record($this->order(), $this->owner, PaymentMethod::Cash);
        $this->payments->record($this->booking(), $this->owner, PaymentMethod::Cash);

        $this->assertSame(['photography_booking', 'printing_order'], Payment::query()->orderBy('payable_type')->pluck('payable_type')->all());
        $this->assertInstanceOf(PrintingOrder::class, Payment::query()->where('payable_type', 'printing_order')->sole()->payable);
    }

    public function test_no_payment_without_a_confirmed_price_and_none_needed_at_zero(): void
    {
        foreach ([Status::Submitted, Status::UnderReview] as $status) {
            $this->refused(fn () => $this->payments->record($this->order($status, null), $this->owner, PaymentMethod::Cash), PaymentException::noPrice()->getMessage());
        }

        $this->refused(fn () => $this->payments->record($this->order(price: '0.00'), $this->owner, PaymentMethod::Cash), PaymentException::noCharge()->getMessage());
        $this->assertSame(0, Payment::count());
    }

    public function test_closed_records_and_unconfirmed_bookings_take_no_payment(): void
    {
        $this->refused(fn () => $this->payments->record($this->order(Status::Cancelled), $this->owner, PaymentMethod::Cash), 'This order is Cancelled, so a payment cannot be recorded for it.');
        $this->refused(fn () => $this->payments->record($this->order(Status::Rejected), $this->owner, PaymentMethod::Cash), 'This order is Rejected, so a payment cannot be recorded for it.');
        $this->refused(fn () => $this->payments->record($this->booking(BookingStatus::Cancelled), $this->owner, PaymentMethod::Cash), 'This booking is Cancelled, so a payment cannot be recorded for it.');
        $this->refused(fn () => $this->payments->record($this->booking(BookingStatus::Proposed), $this->owner, PaymentMethod::Cash), PaymentException::bookingNotConfirmed()->getMessage());

        $this->assertSame(0, Payment::count());
    }

    public function test_a_second_record_writes_nothing(): void
    {
        $order = $this->order();
        $this->payments->record($order, $this->owner, PaymentMethod::Cash);

        $this->refused(fn () => $this->payments->record($order, $this->owner, PaymentMethod::Cash), PaymentException::alreadyPaid()->getMessage());
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, $order->statusHistories()->where('event', OrderEvent::PaymentRecorded)->count());
    }

    public function test_the_database_refuses_a_second_recorded_payment(): void
    {
        $order = $this->order();
        Payment::factory()->paying($order)->create();

        $this->expectException(QueryException::class);
        Payment::factory()->paying($order)->create();
    }

    public function test_the_database_allows_voided_rows_next_to_a_recorded_one(): void
    {
        $order = $this->order();
        Payment::factory()->paying($order)->voided()->create();
        Payment::factory()->paying($order)->voided()->create();
        Payment::factory()->paying($order)->create();

        $this->assertSame(3, Payment::count());
    }

    public function test_a_stale_page_records_nothing(): void
    {
        $order = $this->order();

        $this->refused(fn () => $this->payments->record($order, $this->owner, PaymentMethod::Cash, null, '1500.00'), PaymentException::priceChanged('₱1,850.00')->getMessage());
        $this->assertSame(0, Payment::count());

        $this->payments->record($order, $this->owner, PaymentMethod::Cash, null, '1850');
        $this->assertSame(1, Payment::count());
    }

    public function test_gcash_needs_a_valid_unused_reference_and_cash_needs_none(): void
    {
        $this->refused(fn () => $this->payments->record($this->order(), $this->owner, PaymentMethod::Gcash), PaymentException::gcashReferenceRequired()->getMessage());
        $this->refused(fn () => $this->payments->record($this->order(), $this->owner, PaymentMethod::Gcash, '12345'), PaymentException::gcashReferenceFormat()->getMessage());
        $this->refused(fn () => $this->payments->record($this->order(), $this->owner, PaymentMethod::Gcash, 'ab cd 1234'), PaymentException::gcashReferenceFormat()->getMessage());
        $this->refused(fn () => $this->payments->record($this->order(), $this->owner, PaymentMethod::Gcash, str_repeat('9', 41)), PaymentException::gcashReferenceFormat()->getMessage());

        $first = $this->payments->record($this->order(), $this->owner, PaymentMethod::Gcash, ' gc-0012345678 ');
        $this->assertSame('GC-0012345678', $first->gcash_reference);

        $other = $this->order();
        $this->refused(fn () => $this->payments->record($other, $this->owner, PaymentMethod::Gcash, 'GC-0012345678'), PaymentException::gcashReferenceTaken()->getMessage());

        // Once the first record is voided, the reference is free again
        $this->payments->void($first->payable, $first, $this->owner, 'Wrong order.');
        $this->assertSame('GC-0012345678', $this->payments->record($other, $this->owner, PaymentMethod::Gcash, 'gc-0012345678')->gcash_reference);

        $cash = $this->payments->record($this->order(), $this->owner, PaymentMethod::Cash, 'IGNORED-123');
        $this->assertNull($cash->gcash_reference);
    }

    public function test_only_the_owner_records_or_voids(): void
    {
        $order = $this->order();
        $customer = $order->customer;

        $this->expectException(AuthorizationException::class);
        $this->payments->record($order, $customer, PaymentMethod::Cash);
    }

    public function test_the_customer_history_never_shows_the_gcash_reference_or_the_void_reason(): void
    {
        $order = $this->order();
        $payment = $this->payments->record($order, $this->owner, PaymentMethod::Gcash, 'GC-SECRET-777');
        $this->payments->void($order, $payment, $this->owner, 'Customer showed a fake screenshot.');

        $notes = $order->statusHistories()->pluck('note')->implode(' ');
        $this->assertStringContainsString($payment->reference_number, $notes);
        $this->assertStringContainsString('Payment recorded (GCash)', $notes);
        $this->assertStringNotContainsString('GC-SECRET-777', $notes);
        $this->assertStringNotContainsString('fake screenshot', $notes);
        $this->assertSame([OrderEvent::PaymentRecorded, OrderEvent::PaymentVoided], $order->statusHistories()->get()->pluck('event')->all());

        $this->assertSame('Customer showed a fake screenshot.', $payment->fresh()->void_reason);
    }

    public function test_voiding_needs_a_reason_and_is_refused_once_released(): void
    {
        $order = $this->order(Status::ReadyForPickup);
        $payment = $this->payments->record($order, $this->owner, PaymentMethod::Cash);

        $this->refused(fn () => $this->payments->void($order, $payment, $this->owner, '   '), PaymentException::voidReasonRequired()->getMessage());
        $this->refused(fn () => $this->payments->void($order, $payment, $this->owner, str_repeat('x', 501)), PaymentException::voidReasonRequired()->getMessage());

        app(StatusTransitionService::class)->transition($order, Status::Completed, $this->owner);

        $this->refused(fn () => $this->payments->void($order, $payment, $this->owner, 'Too late.'), PaymentException::released('order')->getMessage());
        $this->assertTrue($payment->fresh()->isRecorded());
    }

    public function test_voiding_is_allowed_on_cancelled_and_rejected_records_and_only_once(): void
    {
        $order = $this->order();
        $payment = $this->payments->record($order, $this->owner, PaymentMethod::Cash);
        app(StatusTransitionService::class)->transition($order, Status::Cancelled, $this->owner);

        $voided = $this->payments->void($order, $payment, $this->owner, 'Order cancelled; money returned at the counter.');
        $this->assertSame(PaymentRecordStatus::Voided, $voided->status);
        $this->assertNull($voided->active);
        $this->assertSame($this->owner->id, $voided->voided_by);
        $this->assertNotNull($voided->voided_at);

        $this->refused(fn () => $this->payments->void($order, $payment, $this->owner, 'Again.'), PaymentException::notRecorded()->getMessage());

        $rejected = $this->order(Status::Rejected);
        $old = Payment::factory()->paying($rejected)->create();
        $this->assertSame(PaymentRecordStatus::Voided, $this->payments->void($rejected, $old, $this->owner, 'Rejected after payment.')->status);
    }

    public function test_a_payment_cannot_be_voided_through_another_record(): void
    {
        $order = $this->order();
        $payment = $this->payments->record($order, $this->owner, PaymentMethod::Cash);

        $this->refused(fn () => $this->payments->void($this->order(), $payment, $this->owner, 'Wrong place.'), PaymentException::wrongPayable()->getMessage());
        $this->assertTrue($payment->fresh()->isRecorded());
    }

    public function test_the_release_gate_for_orders(): void
    {
        $transitions = app(StatusTransitionService::class);

        $unpaid = $this->order(Status::ReadyForPickup);
        $this->refused(fn () => $transitions->transition($unpaid, Status::Completed, $this->owner), PaymentException::RELEASE_MESSAGE);
        $this->assertSame(Status::ReadyForPickup, $unpaid->fresh()->status);

        $this->payments->record($unpaid, $this->owner, PaymentMethod::Cash);
        $this->assertSame(Status::Completed, $transitions->transition($unpaid, Status::Completed, $this->owner)->status);

        $free = $this->order(Status::ReadyForPickup, '0.00');
        $this->assertSame(Status::Completed, $transitions->transition($free, Status::Completed, $this->owner)->status);

        // Other moves are never blocked by payment
        $review = $this->order(Status::UnderReview, null);
        $transitions->transition($review, Status::Accepted, $this->owner, null, '500');
        $transitions->transition($review, Status::Processing, $this->owner);
        $transitions->transition($review, Status::ReadyForPickup, $this->owner);
        $this->assertSame(Status::ReadyForPickup, $review->fresh()->status);
        $this->assertSame(0, Payment::recordedFor($review)->count(), 'Accepting an order never creates a payment');
    }

    public function test_the_release_gate_for_bookings(): void
    {
        $transitions = app(BookingTransitionService::class);

        $unpaid = $this->booking(past: true);
        $this->refused(fn () => $transitions->complete($unpaid, $this->owner), PaymentException::RELEASE_MESSAGE);
        $this->assertSame(BookingStatus::Confirmed, $unpaid->fresh()->status);

        $this->payments->record($unpaid, $this->owner, PaymentMethod::Gcash, 'GC-998877');
        $this->assertSame(BookingStatus::Completed, $transitions->complete($unpaid, $this->owner)->status);

        $free = $this->booking(price: '0.00', past: true);
        $this->assertSame(BookingStatus::Completed, $transitions->complete($free, $this->owner)->status);

        $this->assertSame([BookingEvent::PaymentRecorded], $unpaid->statusHistories()->whereNotNull('event')->get()->pluck('event')->all());
    }

    public function test_the_price_is_locked_while_paid_and_free_again_after_a_void(): void
    {
        $order = $this->order(Status::Processing);
        $payment = $this->payments->record($order, $this->owner, PaymentMethod::Cash);
        $this->refused(fn () => app(StatusTransitionService::class)->changePrice($order, $this->owner, '2000'), PaymentException::PRICE_LOCKED_MESSAGE);
        $this->assertSame('1850.00', $order->fresh()->confirmed_price);

        $this->payments->void($order, $payment, $this->owner, 'Price was wrong.');
        app(StatusTransitionService::class)->changePrice($order, $this->owner, '2000');
        $this->assertSame('2000.00', $order->fresh()->confirmed_price);

        $booking = $this->booking();
        $paid = $this->payments->record($booking, $this->owner, PaymentMethod::Cash);
        $this->refused(fn () => app(BookingTransitionService::class)->changePrice($booking, $this->owner, '3000'), PaymentException::PRICE_LOCKED_MESSAGE);
        $this->payments->void($booking, $paid, $this->owner, 'Price was wrong.');
        app(BookingTransitionService::class)->changePrice($booking, $this->owner, '3000');
        $this->assertSame('3000.00', $booking->fresh()->confirmed_price);
    }

    public function test_prints_from_a_booking_are_paid_on_their_own(): void
    {
        $booking = $this->booking(BookingStatus::Completed, '2500.00', past: true);
        $prints = PrintingOrder::factory()->status(Status::Accepted)->create(['photography_booking_id' => $booking->id, 'customer_id' => $booking->customer_id, 'confirmed_price' => '300.00']);

        $this->payments->record($booking, $this->owner, PaymentMethod::Cash);
        $this->assertSame(PaymentStatus::Unpaid, $prints->fresh()->paymentStatus());

        $this->assertSame('300.00', $this->payments->record($prints, $this->owner, PaymentMethod::Cash)->amount);
        $this->assertSame(['2500.00', '300.00'], Payment::query()->orderBy('id')->pluck('amount')->all());
    }

    public function test_nothing_can_be_mass_assigned_on_a_payment(): void
    {
        $this->expectException(MassAssignmentException::class);

        (new Payment)->fill(['status' => 'voided', 'amount' => '1.00', 'method' => 'gcash', 'received_by' => 1, 'active' => 1]);
    }
}
