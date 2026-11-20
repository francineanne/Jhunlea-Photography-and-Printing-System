<?php

namespace Tests\Feature\Notifications;

use App\Enums\BookingStatus;
use App\Enums\NotificationKind;
use App\Enums\PrintingOrderStatus;
use App\Models\OrderStatusHistory;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Models\User;
use App\Services\UrgentItems;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 10O: what is urgent, said plainly: the amber notice on a customer's own order or booking, and the "Needs your
 * attention" list shown once after signing in. Now is Thursday 5 November 2026, 10:00 in Manila.
 */
class UrgentItemsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $ana;

    private User $ben;

    protected function setUp(): void
    {
        parent::setUp();

        config(['notifications.urgent_on_login' => true]);
        $this->travelTo($this->manila('2026-11-05 10:00'));
        $this->owner = User::factory()->owner()->create();
        $this->ana = User::factory()->create(['name' => 'Ana Cruz']);
        $this->ben = User::factory()->create(['name' => 'Ben Reyes']);
    }

    private function manila(string $local): Carbon
    {
        return Carbon::parse($local, 'Asia/Manila')->utc();
    }

    private function booking(User $customer, BookingStatus $status, string $start, array $extra = []): PhotographyBooking
    {
        return PhotographyBooking::factory()->forCustomer($customer)->status($status)->at($this->manila($start))->create($extra);
    }

    private function readyOrder(User $customer, string $readyAt, array $extra = []): PrintingOrder
    {
        $order = PrintingOrder::factory()->forCustomer($customer)->status(PrintingOrderStatus::ReadyForPickup)->create(['confirmed_price' => '400.00'] + $extra);
        OrderStatusHistory::factory()->create(['printing_order_id' => $order->id, 'from_status' => PrintingOrderStatus::Processing, 'to_status' => PrintingOrderStatus::ReadyForPickup, 'changed_by' => $this->owner->id, 'created_at' => $this->manila($readyAt)]);

        return $order;
    }

    // ---- A) the inline notice ----

    public function test_the_downpayment_notice_names_the_amount_the_deadline_and_what_happens(): void
    {
        $booking = $this->booking($this->ana, BookingStatus::AwaitingDownpayment, '2026-11-12 10:00', ['confirmed_price' => '2000.00', 'downpayment_due_at' => $this->manila('2026-11-09 10:00')]);

        $this->actingAs($this->ana)->get(route('customer.bookings.show', $booking))->assertOk()
            ->assertSee('data-urgent-notice', false)
            ->assertSee('Pay the downpayment of ₱1,000.00 by Nov 9, 2026 10:00 AM. If it is not paid by then, this booking is cancelled automatically and the time is given to other customers.')
            ->assertSee('data-countdown', false);
    }

    public function test_requested_and_proposed_say_when_they_expire_and_other_statuses_say_nothing(): void
    {
        $requested = $this->booking($this->ana, BookingStatus::Requested, '2026-11-12 10:00');
        $proposed = $this->booking($this->ana, BookingStatus::Proposed, '2026-11-12 10:00', ['proposed_starts_at' => $this->manila('2026-11-13 14:00')]);
        $confirmed = $this->booking($this->ana, BookingStatus::Confirmed, '2026-11-14 10:00', ['confirmed_price' => '2000.00']);

        $this->actingAs($this->ana)->get(route('customer.bookings.show', $requested))->assertSee('If the studio does not answer before Nov 12, 2026 10:00 AM, this request expires.');
        $this->actingAs($this->ana)->get(route('customer.bookings.show', $proposed))->assertSee('Accept or ask for another time before Nov 13, 2026 2:00 PM, or this offer expires.');
        $this->actingAs($this->ana)->get(route('customer.bookings.show', $confirmed))->assertOk()->assertDontSee('data-urgent-notice', false);

        // An expired request says nothing urgent (it already reads Expired)
        $expired = $this->booking($this->ana, BookingStatus::Requested, '2026-11-04 10:00');
        $this->actingAs($this->ana)->get(route('customer.bookings.show', $expired))->assertDontSee('data-urgent-notice', false);
    }

    public function test_an_unpaid_order_ready_for_pickup_warns_about_unclaimed_and_a_paid_one_does_not(): void
    {
        $order = $this->readyOrder($this->ana, '2026-11-03 15:00');

        $this->actingAs($this->ana)->get(route('customer.printing.show', $order))->assertOk()
            ->assertSee('Please pick up and pay within 7 days of Nov 3, 2026. After that the shop may close it as Unclaimed, and your next orders must be paid first.');

        $this->actingAs($this->ana)->get(route('customer.printing.show', PrintingOrder::factory()->forCustomer($this->ana)->status(PrintingOrderStatus::Processing)->create()))
            ->assertDontSee('data-urgent-notice', false);
    }

    public function test_the_customer_reminder_says_what_happens_if_not_paid(): void
    {
        $this->assertStringEndsWith('If it is not paid, the booking is cancelled.', NotificationKind::DownpaymentDueSoon->message(['reference' => 'PB-000123', 'due' => 'Nov 9, 2026 10:00 AM']));
    }

    // ---- B) the list after signing in ----

    public function test_the_customer_list_holds_only_their_own_records(): void
    {
        $mine = $this->booking($this->ana, BookingStatus::AwaitingDownpayment, '2026-11-08 10:00', ['confirmed_price' => '2000.00', 'downpayment_due_at' => $this->manila('2026-11-06 10:00')]);
        $offer = $this->booking($this->ana, BookingStatus::Proposed, '2026-11-12 10:00', ['proposed_starts_at' => $this->manila('2026-11-13 14:00')]);
        $order = $this->readyOrder($this->ana, '2026-11-04 15:00');
        $prepay = PrintingOrder::factory()->forCustomer($this->ana)->status(PrintingOrderStatus::Accepted)->create(['confirmed_price' => '500.00', 'requires_prepayment' => true]);
        $later = $this->booking($this->ana, BookingStatus::AwaitingDownpayment, '2026-11-20 10:00', ['confirmed_price' => '2000.00', 'downpayment_due_at' => $this->manila('2026-11-17 10:00')]);
        $theirs = $this->booking($this->ben, BookingStatus::AwaitingDownpayment, '2026-11-08 10:00', ['confirmed_price' => '2000.00', 'downpayment_due_at' => $this->manila('2026-11-06 10:00')]);

        $items = app(UrgentItems::class)->for($this->ana);
        $text = $items->pluck('text')->implode(' | ');

        $this->assertCount(4, $items);
        foreach ([$mine, $offer, $order, $prepay] as $record) {
            $this->assertStringContainsString($record->reference_number, $text);
        }
        $this->assertStringNotContainsString($theirs->reference_number, $text);
        $this->assertStringNotContainsString($later->reference_number, $text, 'a deadline more than 48 hours away is not urgent yet');
        // Most urgent first: the downpayment due tomorrow
        $this->assertStringContainsString($mine->reference_number, $items->first()['text']);
        $this->assertStringContainsString('Pay the downpayment of ₱1,000.00', $items->first()['text']);
    }

    public function test_the_owner_list_names_the_urgent_work_first(): void
    {
        $lost = $this->booking($this->ana, BookingStatus::Requested, '2026-11-20 10:00', ['hold_expires_at' => $this->manila('2026-11-05 08:00')]);
        $soon = $this->booking($this->ben, BookingStatus::Requested, '2026-11-21 10:00', ['hold_expires_at' => $this->manila('2026-11-05 18:00')]);
        $calm = $this->booking($this->ben, BookingStatus::Requested, '2026-11-22 10:00', ['hold_expires_at' => $this->manila('2026-11-07 10:00')]);
        $walkIn = PhotographyBooking::factory()->walkIn()->status(BookingStatus::AwaitingDownpayment)->at($this->manila('2026-11-09 10:00'))
            ->create(['confirmed_price' => '2000.00', 'downpayment_due_at' => $this->manila('2026-11-06 12:00'), 'contact_number' => '09171234567']);
        $shoot = $this->booking($this->ana, BookingStatus::Confirmed, '2026-11-06 09:00', ['confirmed_price' => '2000.00']);
        $old = $this->readyOrder($this->ana, '2026-10-25 15:00');
        $fresh = $this->readyOrder($this->ben, '2026-11-04 15:00');

        $items = app(UrgentItems::class)->for($this->owner);
        $text = $items->pluck('text')->implode(' | ');

        foreach ([$lost, $soon, $walkIn, $shoot, $old] as $record) {
            $this->assertStringContainsString($record->reference_number, $text);
        }
        foreach ([$calm, $fresh] as $record) {
            $this->assertStringNotContainsString($record->reference_number, $text);
        }
        $this->assertStringContainsString($lost->reference_number, $items->first()['text'], 'a request that lost its hold comes first');
        $this->assertStringContainsString('no longer holds its time', $items->first()['text']);

        $walkInItem = $items->first(fn ($item) => str_contains($item['text'], $walkIn->reference_number));
        $this->assertStringContainsString('Walk-in', $walkInItem['text']);
        $this->assertSame(['href' => 'tel:+639171234567', 'text' => '0917 123 4567'], $walkInItem['phone']);
        $this->assertStringContainsString('still to pay', $items->first(fn ($item) => str_contains($item['text'], $shoot->reference_number))['text']);
    }

    public function test_the_list_shows_once_on_the_first_page_after_signing_in(): void
    {
        $this->booking($this->ana, BookingStatus::AwaitingDownpayment, '2026-11-08 10:00', ['confirmed_price' => '2000.00', 'downpayment_due_at' => $this->manila('2026-11-06 10:00')]);

        $this->actingAs($this->ana)->get(route('customer.dashboard'))->assertOk()
            ->assertSee('data-urgent-modal', false)->assertSee('Needs your attention')->assertSee('Got it');
        $this->actingAs($this->ana)->get(route('customer.dashboard'))->assertOk()->assertDontSee('data-urgent-modal', false);
        $this->actingAs($this->ana)->get(route('customer.bookings.index'))->assertOk()->assertDontSee('data-urgent-modal', false);

        // A new sign-in (a fresh session) shows it again
        $this->flushSession();
        $this->actingAs($this->ana)->get(route('customer.dashboard'))->assertSee('data-urgent-modal', false);
    }

    public function test_more_than_five_items_link_the_rest(): void
    {
        foreach (range(1, 7) as $i) {
            $this->booking($this->ana, BookingStatus::Requested, '2026-11-2'.$i.' 10:00', ['hold_expires_at' => $this->manila('2026-11-05 0'.$i.':00')]);
        }

        $this->actingAs($this->owner)->get(route('owner.dashboard'))->assertOk()
            ->assertSee('+2 more')->assertSee(route('owner.bookings.index', ['status' => 'requested']), false);
    }

    public function test_nothing_urgent_means_no_list_and_walk_ins_never_get_one(): void
    {
        $this->actingAs($this->ana)->get(route('customer.dashboard'))->assertOk()->assertDontSee('data-urgent-modal', false);
        $this->actingAs($this->owner)->get(route('owner.dashboard'))->assertOk()->assertDontSee('data-urgent-modal', false);

        $walkIn = User::factory()->walkIn()->create();
        $this->booking($walkIn, BookingStatus::AwaitingDownpayment, '2026-11-08 10:00', ['confirmed_price' => '2000.00', 'downpayment_due_at' => $this->manila('2026-11-06 10:00')]);
        $this->assertNull(app(UrgentItems::class)->onceForLogin($walkIn));
    }

    public function test_the_owner_list_reads_a_fixed_number_of_queries(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(UrgentItems::class)->for($this->owner);

            return count(DB::getQueryLog());
        };

        $this->booking($this->ana, BookingStatus::Requested, '2026-11-20 10:00', ['hold_expires_at' => $this->manila('2026-11-05 08:00')]);
        $this->booking($this->ana, BookingStatus::Confirmed, '2026-11-06 09:00', ['confirmed_price' => '2000.00']);
        $this->readyOrder($this->ana, '2026-10-25 15:00');
        $count(); // the shop settings row is read once and then kept for the request
        $few = $count();

        foreach (range(1, 6) as $i) {
            $this->booking($this->ben, BookingStatus::Requested, '2026-11-2'.$i.' 10:00', ['hold_expires_at' => $this->manila('2026-11-05 08:00')]);
            $this->booking($this->ben, BookingStatus::Confirmed, '2026-11-06 1'.$i.':00', ['confirmed_price' => '2000.00']);
            $this->readyOrder($this->ben, '2026-10-2'.$i.' 15:00');
        }

        $this->assertSame($few, $count());
        $this->assertLessThanOrEqual(15, $few);
    }
}
