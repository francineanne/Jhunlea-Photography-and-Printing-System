<?php

namespace Tests\Feature\Notifications;

use App\Enums\BookingStatus;
use App\Enums\NotificationKind;
use App\Enums\PaymentMethod;
use App\Enums\PrintingOrderStatus as Status;
use App\Exceptions\InvalidStatusTransitionException;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Models\User;
use App\Services\BookingTransitionService;
use App\Services\PaymentService;
use App\Services\StatusTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Booking\SetsUpSchedule;
use Tests\TestCase;

/** Phase 5B: NotificationDispatcher tells the right people, only after a history row, and never leaks private text. */
class NotificationRulesTest extends TestCase
{
    use RefreshDatabase, SetsUpSchedule;

    private User $owner;

    private User $secondOwner;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSchedule();
        $this->owner = User::factory()->owner()->create();
        $this->secondOwner = User::factory()->owner()->create();
        $this->customer = User::factory()->create(['email' => 'ana@example.test', 'phone' => '0917 555 1234']);
    }

    private function orders(): StatusTransitionService
    {
        return app(StatusTransitionService::class);
    }

    private function bookings(): BookingTransitionService
    {
        return app(BookingTransitionService::class);
    }

    private function order(Status $status, ?string $price = null): PrintingOrder
    {
        return PrintingOrder::factory()->forCustomer($this->customer)->status($status)->create(['confirmed_price' => $price]);
    }

    private function booking(BookingStatus $status, string $start = '2026-11-05 10:00', array $attributes = []): PhotographyBooking
    {
        return PhotographyBooking::factory()->forCustomer($this->customer)->status($status)->at($this->manila($start))->create($attributes);
    }

    /** @return list<string> kinds each user received, as "user:kind" */
    private function sent(): array
    {
        return DatabaseNotification::query()->orderBy('created_at')->get()
            ->map(fn ($row) => match ((int) $row->notifiable_id) {
                $this->owner->id => 'owner',
                $this->secondOwner->id => 'owner2',
                $this->customer->id => 'customer',
                default => 'other',
            }.':'.$row->data['kind'])
            ->sort()->values()->all();
    }

    private function assertSent(array $expected): void
    {
        sort($expected);
        $this->assertSame($expected, $this->sent());
    }

    public function test_the_customer_is_told_what_the_shop_did_to_an_order(): void
    {
        $order = $this->order(Status::UnderReview);
        $this->orders()->requestInfo($order, $this->owner, 'Is page 2 in colour?');
        $this->orders()->transition($order, Status::Accepted, $this->owner, null, '480.50');
        $this->orders()->changePrice($order, $this->owner, '500');
        $this->orders()->transition($order, Status::Processing, $this->owner);
        $this->orders()->transition($order, Status::ReadyForPickup, $this->owner);
        app(PaymentService::class)->record($order, $this->owner, PaymentMethod::Cash);
        $this->orders()->transition($order, Status::Completed, $this->owner);

        $this->assertSent(['customer:order_info_requested', 'customer:order_accepted', 'customer:order_price_changed', 'customer:order_processing',
            'customer:order_ready', 'customer:payment_recorded', 'customer:order_completed']);

        $accepted = DatabaseNotification::query()->where('type', 'order_accepted')->sole();
        $this->assertSame(['kind' => 'order_accepted', 'payable_type' => 'printing_order', 'payable_id' => $order->id, 'reference' => $order->reference_number, 'amount' => '480.50'], $accepted->data);
        $this->assertStringContainsString('₱480.50', NotificationKind::OrderAccepted->message($accepted->data));
    }

    public function test_a_rejection_tells_the_customer_without_the_reason(): void
    {
        $order = $this->order(Status::UnderReview);
        $this->orders()->transition($order, Status::Rejected, $this->owner, 'The file is blurry and secret.');

        $this->assertSent(['customer:order_rejected']);
        $row = DatabaseNotification::sole();
        $this->assertStringNotContainsString('blurry', json_encode($row->data).NotificationKind::OrderRejected->message($row->data));
    }

    public function test_the_customer_is_told_what_the_studio_did_to_a_booking(): void
    {
        $proposed = $this->booking(BookingStatus::Requested);
        $this->bookings()->propose($proposed, $this->owner, $this->manila('2026-11-06 14:00'), 'Would 2 PM work?');

        $confirmed = $this->booking(BookingStatus::Requested, '2026-11-05 14:00', ['estimated_price' => '1000.00']);
        $this->bookings()->confirm($confirmed, $this->owner);
        $this->bookings()->changePrice($confirmed, $this->owner, '1200');

        $rejected = $this->booking(BookingStatus::Requested, '2026-11-07 10:00');
        $this->bookings()->reject($rejected, $this->owner, 'Fully booked that week, sorry.');

        $done = $this->booking(BookingStatus::Confirmed, '2026-11-01 10:00', ['confirmed_price' => '0.00']);
        $this->bookings()->complete($done, $this->owner);

        $this->assertSent(['customer:booking_proposed', 'customer:booking_confirmed', 'customer:booking_price_changed', 'customer:booking_rejected', 'customer:booking_completed']);
    }

    public function test_every_owner_is_told_what_a_customer_did(): void
    {
        $this->orders()->recordSubmission($placed = $this->order(Status::Submitted), $this->customer);
        $this->bookings()->recordRequest($requested = $this->booking(BookingStatus::Requested), $this->customer);
        $this->orders()->transition($this->order(Status::Submitted), Status::Cancelled, $this->customer);
        $this->bookings()->cancel($this->booking(BookingStatus::Requested, '2026-11-09 10:00'), $this->customer, 'Plans changed.');

        $asked = $this->order(Status::UnderReview);
        $asked->forceFill(['info_request' => 'Send a PDF.'])->save();
        $this->orders()->recordFilesAdded($asked, $this->customer, 1);

        $offer = $this->booking(BookingStatus::Proposed, '2026-11-05 10:00', ['proposed_starts_at' => $this->manila('2026-11-06 14:00')]);
        $this->bookings()->acceptProposal($offer, $this->customer);
        $again = $this->booking(BookingStatus::Proposed, '2026-11-10 10:00', ['proposed_starts_at' => $this->manila('2026-11-10 14:00')]);
        $this->bookings()->askForAnotherTime($again, $this->customer, 'Morning please.');

        $kinds = ['order_placed', 'booking_requested', 'order_cancelled_by_customer', 'booking_cancelled_by_customer', 'info_answered', 'proposal_accepted', 'another_time_requested'];
        $this->assertSent([...array_map(fn ($k) => "owner:{$k}", $kinds), ...array_map(fn ($k) => "owner2:{$k}", $kinds)]);
        $this->assertSame($placed->reference_number, DatabaseNotification::query()->where('type', 'order_placed')->first()->data['reference']);
        $this->assertSame($requested->id, DatabaseNotification::query()->where('type', 'booking_requested')->first()->data['payable_id']);
    }

    public function test_nobody_is_told_about_their_own_action_and_unlisted_moves_tell_nobody(): void
    {
        // The owner cancels and starts a review: not in the list, so nobody is told
        $this->orders()->transition($this->order(Status::Submitted), Status::UnderReview, $this->owner);
        $this->orders()->transition($this->order(Status::Accepted, '10.00'), Status::Cancelled, $this->owner);
        $this->bookings()->cancel($this->booking(BookingStatus::Confirmed), $this->owner, 'Studio closed.');
        // A customer removing a file
        $this->orders()->recordFileRemoved($this->order(Status::Submitted), $this->customer, 'scan.pdf');

        $this->assertSame([], $this->sent());
    }

    public function test_a_double_submit_or_stale_page_notifies_once_and_a_refused_action_notifies_nobody(): void
    {
        $order = $this->order(Status::UnderReview);
        $this->orders()->requestInfo($order, $this->owner, 'Same text.');
        $this->orders()->requestInfo($order, $this->owner, 'Same text.');
        $this->orders()->changePrice($accepted = $this->order(Status::Accepted, '100.00'), $this->owner, '100');

        $payments = app(PaymentService::class);
        $payments->record($paid = $this->order(Status::Accepted, '50.00'), $this->owner, PaymentMethod::Cash);
        try {
            $payments->record($paid, $this->owner, PaymentMethod::Cash);
        } catch (\DomainException) {
        }

        try {
            $this->orders()->transition($this->order(Status::ReadyForPickup, '99.00'), Status::Completed, $this->owner);
        } catch (InvalidStatusTransitionException) {
        }

        $this->assertSent(['customer:order_info_requested', 'customer:payment_recorded']);
    }

    public function test_payment_notifications_never_carry_the_gcash_reference_or_the_void_reason(): void
    {
        $order = $this->order(Status::Accepted, '1850.00');
        $payments = app(PaymentService::class);
        $payment = $payments->record($order, $this->owner, PaymentMethod::Gcash, 'GC-SECRET-555');
        $payments->void($order, $payment, $this->owner, 'Fake screenshot shown.');

        $this->assertSent(['customer:payment_recorded', 'customer:payment_voided']);

        foreach (DatabaseNotification::all() as $row) {
            $text = json_encode($row->data).' '.NotificationKind::from($row->data['kind'])->message($row->data);
            foreach (['GC-SECRET-555', 'Fake screenshot', 'ana@example.test', '0917 555 1234', 'secret'] as $private) {
                $this->assertStringNotContainsStringIgnoringCase($private, $text);
            }
            $this->assertStringContainsString($payment->reference_number, $text);
        }
    }

    public function test_internal_notes_never_notify(): void
    {
        $this->bookings()->saveInternalNote($this->booking(BookingStatus::Confirmed), $this->owner, 'Customer is difficult.');

        $this->assertSame([], $this->sent());
    }

    public function test_a_failure_to_write_a_notification_never_breaks_the_action(): void
    {
        Log::spy();
        Schema::drop('notifications');

        $order = $this->order(Status::UnderReview);
        $this->orders()->transition($order, Status::Accepted, $this->owner, null, '300');

        $this->assertSame(Status::Accepted, $order->fresh()->status);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context) => $message === 'A notification could not be saved.'
            && $context['kind'] === 'order_accepted' && ! str_contains(json_encode($context), 'example.test'));
    }

    public function test_the_message_map_covers_every_kind(): void
    {
        $data = ['reference' => 'PR-000001', 'payable_type' => 'printing_order', 'payable_id' => 1, 'amount' => '10.00', 'payment' => 'PAY-000001'];

        foreach (NotificationKind::cases() as $kind) {
            $this->assertNotSame('', $kind->title(), $kind->value);
            $this->assertStringContainsString('PR-000001', $kind->message(['kind' => $kind->value] + $data), $kind->value);
            $this->assertNotSame('', $kind->icon(), $kind->value);
            $kind->audience();
            $kind->anchor();
        }

        $this->assertCount(21, NotificationKind::cases());
    }
}
