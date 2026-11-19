<?php

namespace Tests\Feature\Notifications;

use App\Enums\BookingStatus;
use App\Enums\NotificationKind;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\PrintingOrderStatus;
use App\Models\OrderStatusHistory;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Models\User;
use App\Notifications\ActivityNotification;
use App\Services\BookingTransitionService;
use App\Services\NotificationFeed;
use App\Services\PaymentService;
use App\Services\SystemEvents;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Booking\SetsUpSchedule;
use Tests\TestCase;

/**
 * Phase 10K: everything the system does or notices by itself is told ONCE: to every owner, and to the record's own
 * online customer; a walk-in is never notified. "Now" starts on Monday 2 November 2026, 08:00 in Manila.
 */
class SystemNotificationsTest extends TestCase
{
    use RefreshDatabase, SetsUpSchedule;

    private User $owner;

    private User $customer;

    private User $walkIn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSchedule();
        $this->owner = User::factory()->owner()->create(['name' => 'Jhun Owner']);
        $this->customer = User::factory()->create(['name' => 'Ana Cruz']);
        $this->walkIn = User::factory()->walkIn()->create(['name' => 'Juan Dela Cruz', 'phone' => '09171234567']);
    }

    private function run10k(): void
    {
        $this->assertSame(0, app(Kernel::class)->call('notifications:system-events'));
    }

    private function notices(NotificationKind $kind, ?User $to = null): int
    {
        return DatabaseNotification::query()->where('type', $kind->value)
            ->when($to, fn ($query) => $query->where('notifiable_id', $to->id))->count();
    }

    /** What the owner reads for the newest notice of that kind (through the feed, as the pages show it). */
    private function ownerReads(NotificationKind $kind): string
    {
        $row = $this->owner->notifications()->where('type', $kind->value)->latest()->firstOrFail();

        return app(NotificationFeed::class)->present($this->owner, $row)['message'];
    }

    private function requestHeld(User $customer, string $manila = '2026-11-20 10:00', bool $walkIn = false): PhotographyBooking
    {
        $factory = PhotographyBooking::factory()->status(BookingStatus::Requested)->forCustomer($customer)->at($this->manila($manila));

        return ($walkIn ? $factory->walkIn() : $factory)->create(['hold_expires_at' => now()->addHours(48), 'estimated_price' => '2000.00']);
    }

    private function confirmed(User $customer, string $manila, array $extra = []): PhotographyBooking
    {
        $booking = PhotographyBooking::factory()->status(BookingStatus::Requested)->forCustomer($customer)
            ->at($this->manila($manila))->create(['estimated_price' => '2000.00'] + $extra);

        return app(BookingTransitionService::class)->confirm($booking, $this->owner);
    }

    // a) The downpayment expiry

    public function test_an_expired_downpayment_tells_the_owner_and_the_customer_once(): void
    {
        $booking = $this->confirmed($this->customer, '2026-11-04 10:00');
        $this->assertSame(BookingStatus::AwaitingDownpayment, $booking->status);
        $this->travelTo($booking->downpayment_due_at->copy()->addMinute());

        $this->run10k();
        $this->run10k();

        $this->assertSame(BookingStatus::Cancelled, $booking->fresh()->status);
        $this->assertSame(1, $this->notices(NotificationKind::SystemBookingExpired, $this->owner));
        $this->assertSame(1, $this->notices(NotificationKind::DownpaymentExpired, $this->customer));
        $this->assertSame(0, $this->notices(NotificationKind::SystemBookingExpired, $this->customer));
        $this->assertSame("{$booking->reference_number} (Ana Cruz) was cancelled: the downpayment was not paid by Nov 3, 2026 8:00 AM. The time is open again.",
            $this->ownerReads(NotificationKind::SystemBookingExpired));
    }

    public function test_a_walk_in_expiry_tells_the_owner_only(): void
    {
        $booking = $this->confirmed($this->walkIn, '2026-11-04 10:00', ['contact_number' => '09171234567']);
        $this->travelTo($booking->downpayment_due_at->copy()->addMinute());

        $this->run10k();

        $this->assertSame(1, $this->notices(NotificationKind::SystemBookingExpired, $this->owner));
        $this->assertStringStartsWith("{$booking->reference_number} (Juan Dela Cruz, Walk-in) was cancelled", $this->ownerReads(NotificationKind::SystemBookingExpired));
        $this->assertSame(0, DatabaseNotification::query()->where('notifiable_id', $this->walkIn->id)->count());
    }

    public function test_a_payment_recorded_just_before_the_deadline_wins_and_nothing_is_cancelled_or_told(): void
    {
        $booking = $this->confirmed($this->customer, '2026-11-04 10:00');
        $due = $booking->downpayment_due_at->copy();
        $this->travelTo($due->copy()->subMinute());
        app(PaymentService::class)->record($booking, $this->owner, PaymentMethod::Cash, null, null, PaymentKind::Downpayment);
        $this->travelTo($due->copy()->addMinute());

        $this->run10k();

        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
        $this->assertSame(0, $this->notices(NotificationKind::SystemBookingExpired));
        $this->assertSame(0, $this->notices(NotificationKind::DownpaymentExpired));
    }

    // b) and c) Holds that ran out

    public function test_a_request_whose_hold_ran_out_is_told_once_to_both_sides(): void
    {
        $booking = $this->requestHeld($this->customer);

        $this->travelTo(now()->addHours(47));
        $this->run10k();
        $this->assertSame(0, $this->notices(NotificationKind::SystemHoldExpired), 'Still holding');

        $this->travelTo(now()->addHours(2));
        $this->run10k();
        $this->run10k();

        $this->assertSame(1, $this->notices(NotificationKind::SystemHoldExpired, $this->owner));
        $this->assertSame(1, $this->notices(NotificationKind::HoldExpired, $this->customer));
        $this->assertSame("{$booking->reference_number} (Ana Cruz) is no longer holding Fri, Nov 20, 10:00 AM because it was not answered in 48 hours. Answer it now or the time may be taken.",
            $this->ownerReads(NotificationKind::SystemHoldExpired));
        $row = $this->customer->notifications()->sole();
        $this->assertStringStartsWith("The studio has not answered {$booking->reference_number} yet, so the time is no longer reserved for you.", app(NotificationFeed::class)->present($this->customer, $row)['message']);
    }

    public function test_a_walk_in_request_hold_tells_the_owner_only_and_a_passed_time_is_not_told(): void
    {
        $this->requestHeld($this->walkIn, walkIn: true);
        // Its time passes before the hold runs out: it reads "Expired" and nothing is told
        PhotographyBooking::factory()->status(BookingStatus::Requested)->forCustomer($this->customer)->at($this->manila('2026-11-03 10:00'))
            ->create(['hold_expires_at' => now()->addHours(48)]);

        $this->travelTo(now()->addHours(49));
        $this->run10k();

        $this->assertSame(1, $this->notices(NotificationKind::SystemHoldExpired, $this->owner));
        $this->assertStringContainsString('(Juan Dela Cruz, Walk-in)', $this->ownerReads(NotificationKind::SystemHoldExpired));
        $this->assertSame(0, $this->notices(NotificationKind::HoldExpired));
    }

    public function test_an_offered_time_that_ran_out_is_told_once_to_both_sides(): void
    {
        $booking = PhotographyBooking::factory()->status(BookingStatus::Proposed)->forCustomer($this->customer)->at($this->manila('2026-11-20 10:00'))
            ->create(['proposed_starts_at' => $this->manila('2026-11-21 14:00'), 'hold_expires_at' => now()->addHours(48)]);

        $this->travelTo(now()->addHours(49));
        $this->run10k();
        $this->run10k();

        $this->assertSame(1, $this->notices(NotificationKind::SystemProposalExpired, $this->owner));
        $this->assertSame(1, $this->notices(NotificationKind::ProposalExpired, $this->customer));
        $this->assertStringContainsString('Sat, Nov 21, 2:00 PM', $this->ownerReads(NotificationKind::SystemProposalExpired));
        $this->assertSame(0, $this->notices(NotificationKind::SystemHoldExpired));
        $this->assertSame(BookingStatus::Proposed, $booking->fresh()->status, 'Nothing changes: the notice only tells');
    }

    // d) The downpayment deadline in 24 hours

    public function test_the_downpayment_reminder_fires_once_within_24_hours_and_again_after_an_extension(): void
    {
        $booking = $this->confirmed($this->customer, '2026-11-20 10:00'); // due Tue 17 Nov 10:00 Manila

        $this->travelTo($this->manila('2026-11-16 09:59'));
        $this->run10k();
        $this->assertSame(0, $this->notices(NotificationKind::SystemDownpaymentDueSoon));

        $this->travelTo($this->manila('2026-11-16 10:30'));
        $this->run10k();
        $this->travelTo($this->manila('2026-11-16 18:00'));
        $this->run10k();

        $this->assertSame(1, $this->notices(NotificationKind::SystemDownpaymentDueSoon, $this->owner));
        $this->assertSame(1, $this->notices(NotificationKind::DownpaymentDueSoon, $this->customer));
        $this->assertStringContainsString('the downpayment is due by Nov 17, 2026 10:00 AM', $this->ownerReads(NotificationKind::SystemDownpaymentDueSoon));

        // A new deadline is a new occurrence: reminded again once it is 24 hours away
        app(BookingTransitionService::class)->extendDeadline($booking, $this->owner, $this->manila('2026-11-19 10:00'), 'Asked for more time.');
        $this->run10k();
        $this->assertSame(1, $this->notices(NotificationKind::SystemDownpaymentDueSoon, $this->owner));
        $this->travelTo($this->manila('2026-11-18 11:00'));
        $this->run10k();
        $this->run10k();
        $this->assertSame(2, $this->notices(NotificationKind::SystemDownpaymentDueSoon, $this->owner));
        $this->assertSame(2, $this->notices(NotificationKind::DownpaymentDueSoon, $this->customer));
    }

    public function test_the_walk_in_reminder_tells_the_owner_whom_to_call_and_stores_no_phone(): void
    {
        $this->confirmed($this->walkIn, '2026-11-20 10:00', ['contact_number' => '09171234567']);
        $this->travelTo($this->manila('2026-11-16 12:00'));

        $this->run10k();

        $this->assertSame(1, $this->notices(NotificationKind::SystemDownpaymentDueSoon, $this->owner));
        $this->assertSame(0, $this->notices(NotificationKind::DownpaymentDueSoon));
        $this->assertStringEndsWith('Call 0917 123 4567.', $this->ownerReads(NotificationKind::SystemDownpaymentDueSoon));
        $this->assertStringNotContainsString('0917', (string) json_encode(DatabaseNotification::query()->pluck('data')->all()));
    }

    // e) Unclaimed

    public function test_an_unpaid_order_waiting_for_pickup_is_told_to_the_owner_once_after_the_days(): void
    {
        $order = PrintingOrder::factory()->status(PrintingOrderStatus::ReadyForPickup)->forCustomer($this->customer)->create(['confirmed_price' => '450.00']);
        OrderStatusHistory::factory()->create(['printing_order_id' => $order->id, 'changed_by' => $this->owner->id,
            'from_status' => PrintingOrderStatus::Processing, 'to_status' => PrintingOrderStatus::ReadyForPickup]);
        $paid = PrintingOrder::factory()->status(PrintingOrderStatus::ReadyForPickup)->forCustomer($this->customer)->create(['confirmed_price' => '0.00']);
        OrderStatusHistory::factory()->create(['printing_order_id' => $paid->id, 'changed_by' => $this->owner->id, 'to_status' => PrintingOrderStatus::ReadyForPickup]);

        $this->travelTo($this->manila('2026-11-08 20:00'));
        $this->run10k();
        $this->assertSame(0, $this->notices(NotificationKind::SystemOrderUnclaimable), '6 days');

        $this->travelTo($this->manila('2026-11-09 08:00'));
        $this->run10k();
        $this->run10k();

        $this->assertSame(1, $this->notices(NotificationKind::SystemOrderUnclaimable, $this->owner));
        $this->assertSame(0, DatabaseNotification::query()->where('notifiable_id', $this->customer->id)->count());
        $this->assertSame("{$order->reference_number} (Ana Cruz) has waited 7 days for pickup and is unpaid. You may close it as Unclaimed.", $this->ownerReads(NotificationKind::SystemOrderUnclaimable));
    }

    // f) Tomorrow's photoshoot

    public function test_the_shoot_tomorrow_reminder_waits_for_6_pm_and_fires_once(): void
    {
        $online = PhotographyBooking::factory()->status(BookingStatus::Confirmed)->forCustomer($this->customer)->at($this->manila('2026-11-03 14:00'))->create();
        PhotographyBooking::factory()->status(BookingStatus::Confirmed)->forCustomer($this->walkIn)->walkIn()->at($this->manila('2026-11-03 10:00'))->create();
        PhotographyBooking::factory()->status(BookingStatus::Confirmed)->forCustomer($this->customer)->at($this->manila('2026-11-04 10:00'))->create();

        $this->travelTo($this->manila('2026-11-02 17:59'));
        $this->run10k();
        $this->assertSame(0, $this->notices(NotificationKind::SystemShootTomorrow));

        $this->travelTo($this->manila('2026-11-02 18:00'));
        $this->run10k();
        $this->travelTo($this->manila('2026-11-02 23:45'));
        $this->run10k();

        $this->assertSame(2, $this->notices(NotificationKind::SystemShootTomorrow, $this->owner));
        $this->assertSame(1, $this->notices(NotificationKind::ShootTomorrow, $this->customer));
        $this->assertSame(0, DatabaseNotification::query()->where('notifiable_id', $this->walkIn->id)->count());
        $row = $this->customer->notifications()->sole();
        $this->assertSame("Reminder: your photoshoot {$online->reference_number} is tomorrow at 2:00 PM.", app(NotificationFeed::class)->present($this->customer, $row)['message']);
    }

    // Duplicates

    public function test_a_second_runner_while_one_holds_the_lock_sends_nothing(): void
    {
        $this->requestHeld($this->customer);
        $this->travelTo(now()->addHours(49));

        $lock = Cache::lock(SystemEvents::LOCK, 120);
        $this->assertTrue($lock->get());
        $this->assertNull(app(SystemEvents::class)->run());
        $this->assertSame(0, $this->notices(NotificationKind::SystemHoldExpired));
        $lock->release();

        $this->assertSame(1, app(SystemEvents::class)->run()['holds']);
        $this->assertSame(0, app(SystemEvents::class)->run()['holds']);
    }

    public function test_a_page_load_runs_the_check_lazily(): void
    {
        config(['notifications.lazy_system_events' => true]); // off in phpunit.xml (see config/notifications.php)
        $this->requestHeld($this->customer);
        $this->travelTo(now()->addHours(49));

        $this->actingAs($this->owner)->get(route('owner.bookings.index'))->assertOk();
        $this->actingAs($this->owner)->get(route('owner.bookings.index'))->assertOk();

        $this->assertSame(1, $this->notices(NotificationKind::SystemHoldExpired, $this->owner));
    }

    // The owner's page, the bell and the live count

    public function test_the_system_tab_lists_only_the_automatic_notices_and_is_the_owners_only(): void
    {
        $held = $this->requestHeld($this->customer);
        $other = PhotographyBooking::factory()->status(BookingStatus::Requested)->forCustomer($this->customer)->at($this->manila('2026-11-25 10:00'))->create();
        // A notice about something a person did
        $this->owner->notify(new ActivityNotification(['kind' => NotificationKind::BookingRequested->value, 'payable_type' => 'photography_booking',
            'payable_id' => $other->id, 'reference' => $other->reference_number]));
        $this->travelTo(now()->addHours(49));
        $this->run10k();

        $this->actingAs($this->owner)->get(route('owner.notifications.index', ['tab' => 'system']))->assertOk()
            ->assertSee('Request no longer holds its time')->assertSee($held->reference_number)
            ->assertSee('Notification sound')
            // The list holds the system notices only (the bell in the header still shows the latest of every kind)
            ->assertViewHas('items', fn ($items) => $items->count() === 1 && $items->every(fn ($item) => $item['kind']->isSystem()));
        $this->assertSame(['all' => 2, 'unread' => 2, 'system' => 1], app(NotificationFeed::class)->counts($this->owner));
        $this->actingAs($this->owner)->get(route('owner.notifications.index'))->assertOk()->assertSee('System');

        // The bell counts them like any other notice
        $this->actingAs($this->owner)->getJson(route('owner.live'))->assertOk()->assertJsonPath('unread', 2);

        // A customer has no System tab: the page falls back to All
        $this->actingAs($this->customer)->get(route('customer.notifications.index', ['tab' => 'system']))->assertRedirect(route('customer.notifications.index'));
    }

    public function test_the_live_endpoint_returns_the_unread_count_the_chime_listens_to(): void
    {
        $this->actingAs($this->customer)->getJson(route('customer.live'))->assertOk()->assertJsonPath('unread', 0);

        $this->requestHeld($this->customer);
        $this->travelTo(now()->addHours(49));
        $this->run10k();

        $this->actingAs($this->customer)->getJson(route('customer.live'))->assertOk()->assertJsonPath('unread', 1)
            ->assertJsonPath('latest.message', fn ($message) => str_starts_with($message, 'The studio has not answered'));
    }

    public function test_every_new_kind_has_a_title_a_message_and_an_icon(): void
    {
        foreach (NotificationKind::system() as $kind) {
            $this->assertNotSame('', $kind->title());
            $this->assertNotSame('', $kind->message(['reference' => 'PB-000001', 'starts' => '2026-11-20T02:00:00Z', 'due' => '2026-11-17T02:00:00Z', 'hours' => 48, 'days' => 7]));
            $this->assertNotSame('', $kind->icon());
            $this->assertTrue($kind->isSystem());
        }
        $this->assertFalse(NotificationKind::BookingRequested->isSystem());
    }
}
