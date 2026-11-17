<?php

namespace Tests\Feature\Payment;

use App\Enums\AuditAction;
use App\Enums\BookingEvent;
use App\Enums\BookingStatus;
use App\Enums\NotificationKind;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\BookingTransitionException;
use App\Exceptions\PaymentException;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\PhotographyBooking;
use App\Models\PhotographyPackage;
use App\Models\User;
use App\Services\Assistant\KnowledgeBuilder;
use App\Services\AvailabilityService;
use App\Services\BookingTransitionService;
use App\Services\DownpaymentExpiryService;
use App\Services\PaymentLedgerService;
use App\Services\PaymentService;
use App\Support\LocalTime;
use App\Support\PaymentRules;
use App\Support\Slot;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Feature\Booking\SetsUpSchedule;
use Tests\Support\SetsPaymentRules;
use Tests\TestCase;

/**
 * Phase 10B: the photoshoot downpayment. "Now" is Monday 2 November 2026, 08:00 in Manila; photoshoot hours Monday to
 * Saturday 9 to 5. Rules (defaults): a 50% downpayment, due 3 days before the shoot, at least 24 hours after confirming.
 */
class DownpaymentTest extends TestCase
{
    use RefreshDatabase, SetsPaymentRules, SetsUpSchedule;

    private User $owner;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSchedule(noticeHours: 0);
        $this->owner = User::factory()->owner()->create(['name' => 'Jhun Owner']);
        $this->customer = User::factory()->create(['name' => 'Ana Cruz']);
    }

    private function request(string $manila = '2026-11-20 10:00', string $estimate = '2000.00', ?User $customer = null): PhotographyBooking
    {
        return PhotographyBooking::factory()->status(BookingStatus::Requested)->forCustomer($customer ?? $this->customer)
            ->at($this->manila($manila))->create(['estimated_price' => $estimate]);
    }

    private function transitions(): BookingTransitionService
    {
        return app(BookingTransitionService::class);
    }

    private function payments(): PaymentService
    {
        return app(PaymentService::class);
    }

    private function confirmed(string $manila = '2026-11-20 10:00', string $estimate = '2000.00'): PhotographyBooking
    {
        return $this->transitions()->confirm($this->request($manila, $estimate), $this->owner);
    }

    private function pay(PhotographyBooking $booking, PaymentKind $kind, PaymentMethod $method = PaymentMethod::Cash, ?string $gcash = null): Payment
    {
        return $this->payments()->record($booking, $this->owner, $method, $gcash, null, $kind);
    }

    private function refused(callable $action): string
    {
        try {
            $action();
        } catch (PaymentException|BookingTransitionException $e) {
            return $e->getMessage();
        }

        $this->fail('The action was not refused.');
    }

    /** @return list<string> */
    private function kinds(): array
    {
        return DatabaseNotification::query()->orderBy('created_at')->orderBy('id')->pluck('type')->all();
    }

    // The deadline

    public function test_the_deadline_formula(): void
    {
        $rules = app(PaymentRules::class);
        $now = Carbon::now('UTC');

        // Far: the shoot minus 3 days
        $this->assertTrue($rules->dueAt($this->manila('2026-11-20 10:00'), $now)->eq($this->manila('2026-11-17 10:00')));
        // Near: 3 days before is already past, so the customer gets 24 hours from the confirmation
        $this->assertTrue($rules->dueAt($this->manila('2026-11-04 10:00'), $now)->eq($this->manila('2026-11-03 08:00')));
        // Never after the shoot starts
        $this->assertTrue($rules->dueAt($this->manila('2026-11-02 20:00'), $now)->eq($this->manila('2026-11-02 20:00')));
        // Manila calendar: a 00:30 shoot on the 10th is due 00:30 on the 7th in Manila (still the 6th in UTC)
        $due = $rules->dueAt($this->manila('2026-11-10 00:30'), $now);
        $this->assertSame(['2026-11-07 00:30', '2026-11-06'], [LocalTime::format($due, 'Y-m-d H:i'), $due->format('Y-m-d')]);

        // The rules are the owner's settings
        $this->paymentRules(['downpayment_due_days' => 7, 'downpayment_min_hours' => 48]);
        $this->assertTrue(app(PaymentRules::class)->dueAt($this->manila('2026-11-20 10:00'), $now)->eq($this->manila('2026-11-13 10:00')));
        $this->assertTrue(app(PaymentRules::class)->dueAt($this->manila('2026-11-05 10:00'), $now)->eq($this->manila('2026-11-04 08:00')));
    }

    public function test_the_downpayment_is_rounded_up_to_the_peso_and_never_above_the_price(): void
    {
        $rules = app(PaymentRules::class);
        $this->assertSame([100000, 100000, 75100], [$rules->downpaymentCents('2000.00'), $rules->downpaymentCents('1999.50'), $rules->downpaymentCents('1500.99')]);
        // (₱750.495 is rounded UP to ₱751.00)

        $this->paymentRules(['downpayment_percent' => 33]);
        $this->assertSame(33000, app(PaymentRules::class)->downpaymentCents('1000.00'));

        $this->paymentRules(['downpayment_percent' => 100]);
        $this->assertSame(9999, app(PaymentRules::class)->downpaymentCents('99.99'));
        $this->assertSame(0, app(PaymentRules::class)->downpaymentCents('0.00'));
    }

    // Confirming

    public function test_confirming_asks_for_a_downpayment_or_confirms_at_once(): void
    {
        $booking = $this->confirmed();

        $this->assertSame(BookingStatus::AwaitingDownpayment, $booking->status);
        $this->assertTrue($booking->downpayment_due_at->eq($this->manila('2026-11-17 10:00')));
        $this->assertSame(['downpayment' => 100000, 'full' => 200000], $booking->fresh()->paymentOptions());
        $this->assertStringContainsString('Downpayment of ₱1,000.00 due by', $booking->statusHistories()->get()->last()->note);
        $this->assertSame([NotificationKind::DownpaymentRequested->value], $this->kinds());
        $notice = DatabaseNotification::query()->sole();
        $this->assertSame(['1000.00', $booking->downpayment_due_at->toIso8601ZuluString()], [$notice->data['amount'], $notice->data['due']]);
        $this->assertStringContainsString('Pay the downpayment of ₱1,000.00 by Nov 17, 2026', NotificationKind::DownpaymentRequested->message($notice->data));

        // A price of ₱0.00, or no downpayment rule: confirmed at once
        $free = $this->transitions()->confirm($this->request('2026-11-21 10:00'), $this->owner, '0.00');
        $this->assertSame([BookingStatus::Confirmed, null], [$free->status, $free->downpayment_due_at]);

        $this->paymentRules(['downpayment_percent' => 0]);
        $this->assertSame(BookingStatus::Confirmed, $this->confirmed('2026-11-23 10:00')->status);
    }

    public function test_accepting_a_proposal_that_needs_a_downpayment_waits_for_it(): void
    {
        $booking = $this->request();
        $this->transitions()->propose($booking, $this->owner, $this->manila('2026-11-21 14:00'), 'Saturday instead?');
        $this->transitions()->acceptProposal($booking, $this->customer);

        $booking->refresh();
        $this->assertSame(BookingStatus::AwaitingDownpayment, $booking->status);
        $this->assertTrue($booking->downpayment_due_at->eq($this->manila('2026-11-18 14:00')));
        $this->assertStringContainsString('It now waits for the downpayment', NotificationKind::ProposalAccepted->message(DatabaseNotification::query()->where('type', NotificationKind::ProposalAccepted->value)->sole()->data));
    }

    // Holding

    public function test_an_awaiting_downpayment_booking_holds_its_time_until_the_deadline(): void
    {
        $booking = $this->confirmed('2026-11-04 10:00');
        $times = fn () => app(AvailabilityService::class)->slots(60, '2026-11-04', '2026-11-04')->map(fn (Slot $slot) => LocalTime::format($slot->startsAt, 'H:i'))->all();

        $this->assertTrue($booking->holdsTime());
        $this->assertNotContains('10:00', $times());

        $this->travelTo($booking->downpayment_due_at->copy()->addMinute());
        $this->assertFalse($booking->fresh()->holdsTime());
        $this->assertContains('10:00', $times());
    }

    // Recording

    public function test_the_downpayment_confirms_the_booking_and_the_balance_settles_it(): void
    {
        $booking = $this->confirmed();

        $this->assertSame(PaymentException::balanceNeedsDownpayment()->getMessage(), $this->refused(fn () => $this->pay($booking, PaymentKind::Balance)));

        $down = $this->pay($booking, PaymentKind::Downpayment);
        $booking->refresh();
        $this->assertSame(['1000.00', PaymentKind::Downpayment], [$down->amount, $down->kind]);
        $this->assertSame([BookingStatus::Confirmed, null, PaymentStatus::DownpaymentPaid], [$booking->status, $booking->downpayment_due_at, $booking->paymentStatus()]);
        $this->assertSame(['balance' => 100000], $booking->paymentOptions());
        $this->assertSame('1000.00', $booking->amountDue());

        // No second downpayment, no full price on top of it
        $this->assertSame(PaymentException::recordBalanceInstead('₱1,000.00')->getMessage(), $this->refused(fn () => $this->pay($booking, PaymentKind::Downpayment)));
        $this->assertSame(PaymentException::recordBalanceInstead('₱1,000.00')->getMessage(), $this->refused(fn () => $this->pay($booking, PaymentKind::Full)));

        // Completing needs the balance (the release gate), and the shoot has to have started
        $this->travelTo($this->manila('2026-11-20 11:00'));
        $this->assertSame(BookingTransitionException::notPaid()->getMessage(), $this->refused(fn () => $this->transitions()->complete($booking, $this->owner)));

        $balance = $this->pay($booking, PaymentKind::Balance);
        $this->assertSame(['1000.00', PaymentStatus::Paid], [$balance->amount, $booking->fresh()->paymentStatus()]);
        $this->assertSame(PaymentException::alreadyPaid()->getMessage(), $this->refused(fn () => $this->pay($booking, PaymentKind::Balance)));
        $this->transitions()->complete($booking, $this->owner);
        $this->assertSame(BookingStatus::Completed, $booking->fresh()->status);

        // (Notices written in the same second have no order between them)
        $this->assertEqualsCanonicalizing([
            NotificationKind::DownpaymentRequested->value, NotificationKind::DownpaymentRecorded->value, NotificationKind::BalanceRecorded->value, NotificationKind::BookingCompleted->value,
        ], $this->kinds());
        $this->assertSame(['downpayment', 'balance'], AuditLog::query()->where('action', AuditAction::PaymentRecorded)->orderBy('id')->get()->map(fn ($row) => $row->meta['kind'])->all());
    }

    public function test_the_full_price_may_be_paid_instead_of_the_downpayment(): void
    {
        $booking = $this->confirmed();
        $full = $this->pay($booking, PaymentKind::Full, PaymentMethod::Gcash, 'gc-778899');

        $booking->refresh();
        $this->assertSame(['2000.00', 'GC-778899', BookingStatus::Confirmed, PaymentStatus::Paid], [$full->amount, $full->gcash_reference, $booking->status, $booking->paymentStatus()]);
        $this->assertSame([], $booking->paymentOptions());
        $this->assertSame(PaymentException::alreadyPaid()->getMessage(), $this->refused(fn () => $this->pay($booking, PaymentKind::Balance)));

        // A booking confirmed without a downpayment takes the full price only
        $this->paymentRules(['downpayment_percent' => 0]);
        $plain = $this->confirmed('2026-11-21 10:00');
        $this->assertSame(PaymentException::noDownpaymentDue()->getMessage(), $this->refused(fn () => $this->pay($plain, PaymentKind::Downpayment)));
        $this->assertSame(PaymentKind::Full, $this->pay($plain, PaymentKind::Full)->kind);
    }

    public function test_the_database_keeps_one_active_payment_of_each_kind(): void
    {
        $booking = $this->confirmed();
        $this->pay($booking, PaymentKind::Downpayment);

        $this->expectException(UniqueConstraintViolationException::class);
        Payment::factory()->paying($booking)->kind(PaymentKind::Downpayment, '1000.00')->create();
    }

    public function test_a_stale_amount_is_refused(): void
    {
        $booking = $this->confirmed();

        $this->assertSame(PaymentException::priceChanged('₱1,000.00')->getMessage(),
            $this->refused(fn () => $this->payments()->record($booking, $this->owner, PaymentMethod::Cash, null, '2000.00', PaymentKind::Downpayment)));
        $this->assertSame(0, Payment::count());
    }

    // Voiding

    public function test_the_void_rules_and_the_way_back_to_awaiting_downpayment(): void
    {
        $booking = $this->confirmed();
        $down = $this->pay($booking, PaymentKind::Downpayment);
        $balance = $this->pay($booking, PaymentKind::Balance);

        $this->assertSame(PaymentException::voidBalanceFirst()->getMessage(), $this->refused(fn () => $this->payments()->void($booking, $down, $this->owner, 'Wrong booking.')));

        $this->payments()->void($booking, $balance, $this->owner, 'Recorded twice.');
        $this->assertSame([BookingStatus::Confirmed, PaymentStatus::DownpaymentPaid], [$booking->fresh()->status, $booking->fresh()->paymentStatus()]);

        // Without its downpayment the booking waits for one again: 24 hours from now (never after the shoot)
        $this->payments()->void($booking, $down, $this->owner, 'Wrong booking.');
        $booking->refresh();
        $this->assertSame([BookingStatus::AwaitingDownpayment, PaymentStatus::Unpaid], [$booking->status, $booking->paymentStatus()]);
        $this->assertTrue($booking->downpayment_due_at->eq(Carbon::now('UTC')->addHours(24)));
        $this->assertTrue($booking->holdsTime());

        $soon = $this->confirmed('2026-11-02 16:00');
        $soonDown = $this->pay($soon, PaymentKind::Downpayment);
        $this->payments()->void($soon, $soonDown, $this->owner, 'Wrong booking.');
        $this->assertTrue($soon->fresh()->downpayment_due_at->eq($this->manila('2026-11-02 16:00')));
    }

    // Phase 10C: a voided FULL payment asks for the downpayment again, unless none is due for this booking

    public function test_voiding_a_full_payment_sends_the_booking_back_unless_no_downpayment_is_due(): void
    {
        $booking = $this->confirmed();
        $full = $this->pay($booking, PaymentKind::Full);
        $this->payments()->void($booking, $full, $this->owner, 'Wrong booking.');
        $booking->refresh();
        $this->assertSame(BookingStatus::AwaitingDownpayment, $booking->status);
        $this->assertTrue($booking->downpayment_due_at->eq(Carbon::now('UTC')->addHours(24)));

        // Waived for this booking: it stays confirmed, the full price due on the day
        $waived = $this->confirmed('2026-11-21 10:00');
        $this->transitions()->waiveDownpayment($waived, $this->owner, 'Regular customer.');
        $this->payments()->void($waived, $this->pay($waived, PaymentKind::Full), $this->owner, 'Wrong booking.');
        $this->assertSame(BookingStatus::Confirmed, $waived->fresh()->status);

        // A price of 0 needs nothing; a 0% rule asks for no downpayment
        $this->paymentRules(['downpayment_percent' => 0]);
        $plain = $this->confirmed('2026-11-23 10:00');
        $this->payments()->void($plain, $this->pay($plain, PaymentKind::Full), $this->owner, 'Wrong booking.');
        $this->assertSame([BookingStatus::Confirmed, null], [$plain->fresh()->status, $plain->fresh()->downpayment_due_at]);
    }

    public function test_a_downpayment_waived_at_the_counter_also_keeps_a_voided_full_payment_confirmed(): void
    {
        $package = PhotographyPackage::factory()->published()->create(['duration_minutes' => 60, 'max_people' => 4, 'price' => '1500.00', 'price_requires_confirmation' => false]);
        $this->actingAs($this->owner)->post(route('owner.bookings.walk-in.store'), [
            'counter_token' => (string) Str::uuid(), 'new_customer_name' => 'Lola Walk-in', 'package' => $package->id, 'custom_theme_text' => 'Plain wall',
            'date' => '2026-11-03', 'time' => '10:00', 'group_size' => 2, 'contact_number' => '0917 555 0101', 'confirmed_price' => '1500',
            'waive' => '1', 'waive_reason' => 'Regular customer.',
        ])->assertSessionHasNoErrors();
        $booking = PhotographyBooking::query()->latest('id')->first();
        $this->assertSame(1, $booking->statusHistories()->where('event', BookingEvent::DownpaymentWaived)->count());

        $this->payments()->void($booking, $this->pay($booking, PaymentKind::Full), $this->owner, 'Wrong booking.');
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
    }

    // Expiry

    public function test_an_unpaid_downpayment_expires_cancels_frees_the_time_notifies_and_audits_once(): void
    {
        $booking = $this->confirmed('2026-11-04 10:00');
        $other = $this->confirmed('2026-11-20 10:00');
        $expiry = app(DownpaymentExpiryService::class);

        $this->assertSame(0, $expiry->expireDue());
        $this->travelTo($booking->downpayment_due_at->copy()->addMinute());

        $this->assertSame(1, $expiry->expireDue());
        $this->assertSame(0, $expiry->expireDue(), 'Running it again cancels nothing twice');

        $booking->refresh();
        $this->assertSame([BookingStatus::Cancelled, 'Downpayment not paid by the deadline.'], [$booking->status, $booking->cancellation_reason]);
        $this->assertSame(BookingStatus::AwaitingDownpayment, $other->fresh()->status);
        $this->assertFalse($booking->holdsTime());

        $history = $booking->statusHistories()->get()->last();
        $this->assertSame([null, BookingStatus::Cancelled], [$history->changed_by, $history->to_status]);
        $audit = AuditLog::query()->where('action', AuditAction::BookingDownpaymentExpired)->sole();
        $this->assertSame(['System', 'system', null], [$audit->actor_name, $audit->actor_role, $audit->actor_id]);
        $this->assertSame(1, DatabaseNotification::query()->where('type', NotificationKind::DownpaymentExpired->value)->where('notifiable_id', $this->customer->id)->count());

        // The customer's timeline reads "System"
        $this->actingAs($this->customer)->get(route('customer.bookings.show', $booking))->assertOk()->assertSee('System')->assertSee('Downpayment not paid by the deadline.');
        $this->assertTrue(app(Kernel::class)->call('bookings:expire-unpaid') === 0);
    }

    public function test_a_payment_recorded_first_wins_over_the_expiry(): void
    {
        $booking = $this->confirmed('2026-11-04 10:00');
        $this->pay($booking, PaymentKind::Downpayment);
        $this->travelTo($this->manila('2026-11-03 09:00'));

        $this->assertFalse($this->transitions()->expire($booking));
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
    }

    public function test_the_expiry_also_runs_when_a_booking_page_is_opened(): void
    {
        $booking = $this->confirmed('2026-11-04 10:00');
        $this->travelTo($booking->downpayment_due_at->copy()->addHour());

        $this->actingAs($this->owner)->get(route('owner.bookings.index'))->assertOk();
        $this->assertSame(BookingStatus::Cancelled, $booking->fresh()->status);
    }

    // Owner tools

    public function test_the_owner_extends_the_deadline_or_waives_the_downpayment(): void
    {
        $booking = $this->confirmed();

        $this->assertSame(BookingTransitionException::deadlineOutOfRange()->getMessage(), $this->refused(fn () => $this->transitions()->extendDeadline($booking, $this->owner, $this->manila('2026-11-21 10:00'), 'Late salary.')));
        $this->transitions()->extendDeadline($booking, $this->owner, $this->manila('2026-11-19 18:00'), 'Late salary.');
        $this->assertTrue($booking->fresh()->downpayment_due_at->eq($this->manila('2026-11-19 18:00')));
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::BookingDeadlineExtended)->count());

        $this->actingAs($this->owner)->post(route('owner.bookings.waive', $booking), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($this->owner)->post(route('owner.bookings.waive', $booking), ['reason' => 'Regular customer.'])->assertSessionHas('status');
        $booking->refresh();
        $this->assertSame([BookingStatus::Confirmed, null, ['full' => 200000]], [$booking->status, $booking->downpayment_due_at, $booking->paymentOptions()]);
        $this->assertSame('Regular customer.', AuditLog::query()->where('action', AuditAction::BookingDownpaymentWaived)->sole()->meta['reason']);
        $this->assertContains(NotificationKind::DownpaymentWaived->value, $this->kinds());

        $this->actingAs($this->customer)->post(route('owner.bookings.waive', $booking), ['reason' => 'x'])->assertForbidden();
    }

    // Cancelling

    public function test_the_customer_cancels_and_the_downpayment_stays(): void
    {
        $booking = $this->confirmed();
        $this->pay($booking, PaymentKind::Downpayment);

        $this->actingAs($this->customer)->get(route('customer.bookings.show', $booking))->assertOk()->assertSee('Your downpayment of ₱1,000.00 is non-refundable.');
        $this->transitions()->cancel($booking, $this->customer);

        $this->assertSame(BookingStatus::Cancelled, $booking->fresh()->status);
        $this->assertSame(1, Payment::query()->recorded()->count());
        $this->assertSame(100000, app(PaymentLedgerService::class)->totals(['method' => null, 'status' => null, 'search' => '', 'from' => null, 'to' => null])['cents']);

        // Awaiting its downpayment, the customer may cancel at any time
        $awaiting = $this->confirmed('2026-11-02 15:00');
        $this->transitions()->cancel($awaiting, $this->customer);
        $this->assertSame(BookingStatus::Cancelled, $awaiting->fresh()->status);
    }

    public function test_the_owner_cancel_keeps_or_refunds_and_the_no_show_reason_waits_for_the_start(): void
    {
        $kept = $this->confirmed();
        $this->pay($kept, PaymentKind::Downpayment);
        $this->assertSame(BookingTransitionException::paymentChoiceRequired()->getMessage(), $this->refused(fn () => $this->transitions()->cancel($kept, $this->owner, 'Studio closed.')));
        $this->assertSame(BookingTransitionException::noShowTooEarly()->getMessage(), $this->refused(fn () => $this->transitions()->cancel($kept, $this->owner, 'Did not show up', 'keep')));

        $this->transitions()->cancel($kept, $this->owner, 'Studio closed.', 'keep');
        $this->assertSame([BookingStatus::Cancelled, 1], [$kept->fresh()->status, Payment::recordedFor($kept)->count()]);

        $refunded = $this->confirmed('2026-11-21 10:00');
        $this->pay($refunded, PaymentKind::Downpayment);
        $this->pay($refunded, PaymentKind::Balance);
        $this->assertSame(BookingTransitionException::refundReasonRequired()->getMessage(), $this->refused(fn () => $this->transitions()->cancel($refunded, $this->owner, null, 'refund')));
        $this->actingAs($this->owner)->post(route('owner.bookings.cancel', $refunded), ['reason' => 'Studio flooded.', 'payment_choice' => 'refund', 'refund_reason' => 'Money returned in cash.'])
            ->assertSessionHas('status');
        $this->assertSame(0, Payment::recordedFor($refunded)->count());
        $this->assertSame(2, AuditLog::query()->where('action', AuditAction::PaymentRefunded)->count());
        $this->assertSame(['Money returned in cash.', 'Money returned in cash.'], Payment::query()->where('payable_id', $refunded->id)->pluck('void_reason')->all());

        // After the start time the preset reason is accepted
        $noShow = $this->confirmed('2026-11-03 10:00');
        $this->pay($noShow, PaymentKind::Downpayment);
        $this->travelTo($this->manila('2026-11-03 11:00'));
        $this->transitions()->cancel($noShow, $this->owner, 'Did not show up', 'keep');
        $this->assertSame('Did not show up', $noShow->fresh()->cancellation_reason);
    }

    // Income

    public function test_a_downpayment_and_its_balance_on_two_days_add_up_to_the_price(): void
    {
        $booking = $this->confirmed();
        $this->pay($booking, PaymentKind::Downpayment);
        $this->travelTo($this->manila('2026-11-20 09:00'));
        $this->pay($booking, PaymentKind::Balance);

        $ledger = app(PaymentLedgerService::class);
        $day = fn (string $date) => $ledger->totals(['method' => null, 'status' => null, 'search' => '', 'from' => $date, 'to' => $date]);
        $this->assertSame([100000, 100000, 200000], [$day('2026-11-02')['cents'], $day('2026-11-20')['cents'], $ledger->totals(['method' => null, 'status' => null, 'search' => '', 'from' => null, 'to' => null])['cents']]);

        $this->actingAs($this->owner)->get(route('owner.reports.end-of-day', ['date' => '2026-11-02']))->assertOk()->assertSee('₱1,000.00');
        $this->actingAs($this->owner)->get(route('owner.payments.receipt', Payment::query()->latest('id')->first()))->assertOk()
            ->assertSee('Balance')->assertSee('Paid so far')->assertSee('₱2,000.00');

        // While it waited for its balance, only the balance was awaiting payment
        $this->travelTo($this->manila('2026-11-03 09:00'));
        $other = $this->confirmed('2026-11-21 10:00', '3000.00');
        $this->pay($other, PaymentKind::Downpayment);
        $this->assertSame(150000, $ledger->sumConfirmedCents($ledger->awaitingBookings()));
    }

    // Walk-in

    public function test_a_walk_in_booking_takes_the_downpayment_the_full_price_or_a_waiver_now(): void
    {
        $package = PhotographyPackage::factory()->published()->create(['duration_minutes' => 60, 'max_people' => 4, 'price' => '1500.00', 'price_requires_confirmation' => false]);
        $send = fn (array $overrides) => $this->actingAs($this->owner)->post(route('owner.bookings.walk-in.store'), $overrides + [
            'counter_token' => (string) Str::uuid(), 'new_customer_name' => 'Lola Walk-in', 'package' => $package->id, 'custom_theme_text' => 'Plain wall',
            'date' => '2026-11-03', 'group_size' => 2, 'contact_number' => '0917 555 0101', 'confirmed_price' => '1500',
        ]);

        $send(['time' => '10:00'])->assertSessionHasErrors('pay_kind');
        $this->assertSame(0, PhotographyBooking::count());

        $send(['time' => '10:00', 'pay_kind' => 'downpayment', 'pay_method' => 'cash'])->assertSessionHasNoErrors();
        $down = PhotographyBooking::query()->latest('id')->first();
        $this->assertSame([BookingStatus::Confirmed, PaymentStatus::DownpaymentPaid, '750.00'], [$down->status, $down->paymentStatus(), $down->recordedPayment->amount]);

        $send(['time' => '13:00', 'pay_kind' => 'full', 'pay_method' => 'gcash', 'gcash_reference' => 'WALK-123456'])->assertSessionHasNoErrors();
        $this->assertSame(PaymentStatus::Paid, PhotographyBooking::query()->latest('id')->first()->paymentStatus());

        $send(['time' => '15:00', 'waive' => '1'])->assertSessionHasErrors('waive_reason');
        $send(['time' => '15:00', 'waive' => '1', 'waive_reason' => 'Regular customer.'])->assertSessionHasNoErrors();
        $waived = PhotographyBooking::query()->latest('id')->first();
        $this->assertSame([BookingStatus::Confirmed, PaymentStatus::Unpaid], [$waived->status, $waived->paymentStatus()]);
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::BookingDownpaymentWaived)->count());

        // Walk-in customers are never notified
        $this->assertSame(0, DatabaseNotification::query()->whereIn('notifiable_id', User::query()->where('is_walk_in', true)->pluck('id'))->count());
    }

    // Lumi and the settings

    public function test_lumi_reads_the_rules_from_the_settings_and_the_owner_saves_them_audited(): void
    {
        $this->assertStringContainsString('downpayment of 50% of its confirmed price', app(KnowledgeBuilder::class)->stable());

        $this->actingAs($this->owner)->post(route('owner.settings.payment-rules'), [
            'downpayment_percent' => 101, 'downpayment_due_days' => 3, 'downpayment_min_hours' => 24, 'prepay_from' => '300', 'unclaimed_after_days' => 7,
        ])->assertSessionHasErrors('downpayment_percent');

        $this->actingAs($this->owner)->post(route('owner.settings.payment-rules'), [
            'downpayment_percent' => 30, 'downpayment_due_days' => 5, 'downpayment_min_hours' => 12, 'prepay_from' => '500.00', 'unclaimed_after_days' => 10,
            'gcash_name' => 'Jhunlea Studio', 'gcash_number' => '0917 000 1111',
        ])->assertSessionHas('status');

        $knowledge = app(KnowledgeBuilder::class)->stable();
        $this->assertStringContainsString('downpayment of 30% of its confirmed price', $knowledge);
        $this->assertStringContainsString('due 5 days before the shoot', $knowledge);
        $this->assertStringContainsString('₱500.00 or more is paid in full before printing', $knowledge);
        $audit = AuditLog::query()->where('action', AuditAction::PaymentRulesChanged)->sole();
        $this->assertContains('gcash_number', $audit->meta['keys']);
        $this->assertArrayNotHasKey('gcash_number', $audit->meta['changes']);

        // The customer's How to pay box shows the GCash details and the deadline
        $booking = $this->confirmed();
        $this->actingAs($this->customer)->get(route('customer.bookings.show', $booking))->assertOk()
            ->assertSee('How to pay')->assertSee('₱600.00')->assertSee('Jhunlea Studio 0917 000 1111')->assertSee('Payment for '.$booking->reference_number.', GCash ref: ');
    }
}
