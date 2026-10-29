<?php

namespace Tests\Feature\Booking;

use App\Enums\BookingEvent;
use App\Enums\BookingStatus as Status;
use App\Exceptions\BookingTransitionException;
use App\Exceptions\PaymentException;
use App\Models\BlockedDate;
use App\Models\BookingStatusHistory;
use App\Models\Payment;
use App\Models\PhotographyBooking;
use App\Models\PhotographyPackage;
use App\Models\User;
use App\Services\BookingTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Phase 4C: the owner's booking list, one booking with its schedule panel, and the studio's actions. */
class OwnerBookingsTest extends TestCase
{
    use RefreshDatabase, SetsUpSchedule;

    private User $owner;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSchedule();
        $this->owner = User::factory()->owner()->create(['name' => 'Jhun Owner']);
        $this->customer = User::factory()->create(['name' => 'Ana Reyes', 'email' => 'ana@example.test']);
    }

    private function booking(Status $status = Status::Requested, string $manila = '2026-11-05 10:00', array $attributes = [], ?User $customer = null): PhotographyBooking
    {
        return PhotographyBooking::factory()->forCustomer($customer ?? $this->customer)->status($status)->at($this->manila($manila))->create($attributes);
    }

    private function act(string $action, PhotographyBooking $booking, array $data = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)->from(route('owner.bookings.show', $booking))->post(route("owner.bookings.{$action}", $booking), $data);
    }

    // Access

    public function test_customers_and_guests_never_reach_the_owner_pages_or_actions(): void
    {
        $booking = $this->booking();

        $this->get(route('owner.bookings.index'))->assertRedirect(route('login'));
        $this->actingAs($this->customer)->get(route('owner.bookings.index'))->assertForbidden();
        $this->actingAs($this->customer)->get(route('owner.bookings.show', $booking))->assertForbidden();

        foreach (['confirm', 'propose', 'reject', 'complete', 'cancel', 'price', 'notes'] as $action) {
            $this->act($action, $booking, ['reason' => 'x', 'note' => 'x', 'confirmed_price' => '1', 'starts_at' => '2026-11-06T06:00:00Z', 'internal_notes' => 'x'], $this->customer)->assertForbidden();
        }

        $fresh = $booking->fresh();
        $this->assertSame(Status::Requested, $fresh->status);
        $this->assertNull($fresh->confirmed_price);
        $this->assertNull($fresh->internal_notes);
        $this->assertSame(0, BookingStatusHistory::count());
    }

    public function test_the_owner_cannot_answer_a_proposal_for_the_customer_or_book(): void
    {
        $booking = $this->booking();
        app(BookingTransitionService::class)->propose($booking, $this->owner, $this->manila('2026-11-06 14:00'), 'Would 2 PM work?');

        $this->actingAs($this->owner)->post(route('customer.bookings.accept', $booking))->assertForbidden();
        $this->actingAs($this->owner)->get(route('customer.bookings.create'))->assertForbidden();
        $this->assertSame(Status::Proposed, $booking->fresh()->status);
    }

    // The list

    public function test_the_requested_tab_opens_first_and_every_tab_has_a_count(): void
    {
        $request = $this->booking();
        $confirmed = $this->booking(Status::Confirmed, '2026-11-06 10:00');

        $response = $this->actingAs($this->owner)->get(route('owner.bookings.index'))->assertOk()
            ->assertSee($request->reference_number)
            ->assertDontSee($confirmed->reference_number);

        foreach (Status::cases() as $status) {
            $response->assertSee($status->label());
        }
        $response->assertSeeInOrder(['Requested', '1', 'New time proposed', '0', 'Confirmed', '1', 'Rejected', '0', 'Cancelled', '0', 'Completed', '0', 'All bookings', '2']);

        $this->actingAs($this->owner)->get(route('owner.bookings.index', ['status' => 'all']))->assertSee($request->reference_number)->assertSee($confirmed->reference_number);
    }

    public function test_confirmed_bookings_are_listed_soonest_first(): void
    {
        $late = $this->booking(Status::Confirmed, '2026-11-20 10:00');
        $soon = $this->booking(Status::Confirmed, '2026-11-06 10:00');
        $middle = $this->booking(Status::Confirmed, '2026-11-12 10:00');

        $this->actingAs($this->owner)->get(route('owner.bookings.index', ['status' => 'confirmed']))
            ->assertSeeInOrder([$soon->reference_number, $middle->reference_number, $late->reference_number]);
    }

    public function test_search_by_reference_name_or_contact_and_filters(): void
    {
        $ana = $this->booking(attributes: ['contact_number' => '0917 111 2222']);
        $ben = $this->booking(manila: '2026-11-12 10:00', customer: User::factory()->create(['name' => 'Ben Cruz']), attributes: ['contact_number' => '0999 888 7777']);
        $otherPackage = PhotographyPackage::factory()->published()->create(['name' => 'Family Day']);
        $ben->forceFill(['photography_package_id' => $otherPackage->id])->save();

        $list = fn (array $query) => $this->actingAs($this->owner)->get(route('owner.bookings.index', $query + ['status' => 'all']));

        $list(['q' => $ana->reference_number])->assertSee($ana->reference_number)->assertDontSee($ben->reference_number);
        $list(['q' => 'Ben'])->assertSee($ben->reference_number)->assertDontSee($ana->reference_number);
        $list(['q' => '888 7777'])->assertSee($ben->reference_number)->assertDontSee($ana->reference_number);
        $list(['package' => $otherPackage->id])->assertSee($ben->reference_number)->assertDontSee($ana->reference_number);
        // The shop's calendar day: Nov 12 in Manila
        $list(['from' => '2026-11-12', 'to' => '2026-11-12'])->assertSee($ben->reference_number)->assertDontSee($ana->reference_number);
    }

    public function test_wildcards_in_the_search_are_matched_literally(): void
    {
        $booking = $this->booking();

        foreach (['%', '_', '\\', '%%%%', "' OR 1=1 --"] as $hostile) {
            $this->actingAs($this->owner)->get(route('owner.bookings.index', ['status' => 'all', 'q' => $hostile]))
                ->assertOk()->assertDontSee($booking->reference_number)->assertSee('No bookings found');
        }
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function hostileFilters(): array
    {
        return [
            'unknown status' => [['status' => 'paid']],
            'array status' => [['status' => ['requested']]],
            'long search' => [['q' => str_repeat('a', 61)]],
            'array search' => [['q' => ['x']]],
            'unknown package' => [['package' => 999999]],
            'package not a number' => [['package' => '1 or 1=1']],
            'bad date' => [['from' => '2026-13-45']],
            'backwards dates' => [['from' => '2026-11-10', 'to' => '2026-11-01']],
        ];
    }

    #[DataProvider('hostileFilters')]
    public function test_hostile_filters_are_refused_safely(array $query): void
    {
        $this->actingAs($this->owner)->get(route('owner.bookings.index', $query))
            ->assertRedirect(route('owner.bookings.index'))->assertSessionHasErrors();
    }

    // One booking

    public function test_the_detail_shows_the_customer_the_request_and_named_history(): void
    {
        $booking = $this->booking(attributes: ['contact_number' => '+63 (917) 555-0142', 'notes' => 'Bringing a toga.']);
        app(BookingTransitionService::class)->recordRequest($booking, $this->customer);
        app(BookingTransitionService::class)->saveInternalNote($booking, $this->owner, 'Regular customer.');

        $this->actingAs($this->owner)->get(route('owner.bookings.show', $booking))->assertOk()
            ->assertSee('Ana Reyes')
            ->assertSee('ana@example.test')
            ->assertSee('+63 (917) 555-0142')
            ->assertSee('href="tel:+639175550142"', false)
            ->assertSee('href="mailto:ana@example.test"', false)
            ->assertSee('Bringing a toga.')
            ->assertSee('Customer · Ana Reyes')
            ->assertSee('Internal notes')
            ->assertSee('Regular customer.');
    }

    public function test_the_schedule_panel_shows_competing_requests_with_links(): void
    {
        $booking = $this->booking();
        $other = $this->booking(manila: '2026-11-05 10:30', customer: User::factory()->create());

        $this->actingAs($this->owner)->get(route('owner.bookings.show', $booking))
            ->assertSee('Schedule check')
            ->assertSee('Other requests for this time')
            ->assertSee(route('owner.bookings.show', $other), false)
            ->assertSee('Confirm booking');
    }

    public function test_a_clash_disables_confirm_with_the_reason(): void
    {
        $booking = $this->booking();
        $this->booking(Status::Confirmed, '2026-11-05 10:30', customer: User::factory()->create());

        $this->actingAs($this->owner)->get(route('owner.bookings.show', $booking))
            ->assertSee('This time clashes with a confirmed booking')
            ->assertDontSee('Confirm booking');

        $this->act('confirm', $booking, ['outside_schedule' => '1'])->assertSessionHas('error');
        $this->assertSame(Status::Requested, $booking->fresh()->status);
    }

    public function test_a_broken_rule_asks_for_confirm_anyway_and_the_history_says_so(): void
    {
        $booking = $this->booking();
        BlockedDate::factory()->create(['date' => '2026-11-05', 'reason' => 'Studio repairs']);

        $this->actingAs($this->owner)->get(route('owner.bookings.show', $booking))
            ->assertSee('The studio blocked Thu, Nov 5, 2026 (Studio repairs).')
            ->assertSee('Confirm anyway');

        $this->act('confirm', $booking)->assertSessionHas('error');
        $this->assertSame(Status::Requested, $booking->fresh()->status);

        $this->act('confirm', $booking, ['outside_schedule' => '1'])->assertSessionHas('status');
        $this->assertSame(Status::Confirmed, $booking->fresh()->status);
        $this->assertStringStartsWith('Confirmed outside the usual schedule: The studio blocked', BookingStatusHistory::sole()->note);
    }

    // Actions

    public function test_confirm_uses_the_estimate_or_the_typed_price_and_a_double_click_writes_once(): void
    {
        $booking = $this->booking(attributes: ['estimated_price' => '1850.00']);

        $this->act('confirm', $booking, ['confirmed_price' => '12.345'])->assertSessionHasErrors('confirmed_price');
        $this->act('confirm', $booking, ['confirmed_price' => '', 'note' => 'See you.'])->assertSessionHas('status');
        $this->act('confirm', $booking)->assertSessionHas('error');

        $this->assertSame('1850.00', $booking->fresh()->confirmed_price);
        $this->assertSame(1, BookingStatusHistory::count());

        $other = $this->booking(manila: '2026-11-06 10:00', attributes: ['estimated_price' => '1850.00']);
        $this->act('confirm', $other, ['confirmed_price' => '2000'])->assertSessionHas('status');
        $this->assertSame('2000.00', $other->fresh()->confirmed_price);
    }

    public function test_a_package_priced_on_request_needs_a_price_to_confirm_or_propose(): void
    {
        $booking = $this->booking(attributes: ['estimated_price' => null]);

        $this->act('confirm', $booking)->assertSessionHasErrors('confirmed_price');
        $this->act('propose', $booking, ['starts_at' => '2026-11-06T06:00:00Z', 'note' => 'Would 2 PM work?'])->assertSessionHasErrors('confirmed_price');
        $this->assertSame(Status::Requested, $booking->fresh()->status);

        $this->act('confirm', $booking, ['confirmed_price' => '3200'])->assertSessionHas('status');
        $this->assertSame('3200.00', $booking->fresh()->confirmed_price);
    }

    public function test_two_overlapping_confirms_cannot_both_win(): void
    {
        $first = $this->booking();
        $second = $this->booking(manila: '2026-11-05 10:30', customer: User::factory()->create());

        $this->act('confirm', $first)->assertSessionHas('status');
        $this->act('confirm', $second, ['outside_schedule' => '1'])->assertSessionHas('error', BookingTransitionException::conflict()->getMessage());

        $this->assertSame(1, PhotographyBooking::query()->where('status', Status::Confirmed)->count());
    }

    public function test_propose_lists_free_times_and_sends_one(): void
    {
        $booking = $this->booking();

        $this->actingAs($this->owner)->get(route('owner.bookings.show', $booking))
            ->assertSee('Offer another time')
            ->assertSee('Thu, Nov 5, 2026')
            ->assertSee('value="2026-11-06T06:00:00Z"', false);

        $this->actingAs($this->owner)->get(route('owner.bookings.show', ['booking' => $booking, 'propose_from' => '2026-11-10']))
            ->assertSee('Tue, Nov 10, 2026')->assertDontSee('value="2026-11-05T01:00:00Z"', false);
        $this->actingAs($this->owner)->get(route('owner.bookings.show', ['booking' => $booking, 'propose_from' => '2026-02-31']))->assertOk();

        $this->act('propose', $booking, ['starts_at' => '2026-11-06T06:00:00Z'])->assertSessionHasErrors('note');
        $this->act('propose', $booking, ['starts_at' => '2026-11-06T06:00:00Z', 'note' => str_repeat('a', 501)])->assertSessionHasErrors('note');
        $this->act('propose', $booking, ['starts_at' => '2026-11-08T02:00:00Z', 'note' => 'Sunday?'])->assertSessionHas('error');
        $this->act('propose', $booking, ['starts_at' => '2026-11-06T06:00:00Z', 'note' => 'The morning is full.'])->assertSessionHas('status');

        $fresh = $booking->fresh();
        $this->assertSame(Status::Proposed, $fresh->status);
        $this->assertSame('2026-11-06 06:00:00', $fresh->proposed_starts_at->format('Y-m-d H:i:s'));
        $this->assertSame(BookingEvent::ProposalSent, BookingStatusHistory::sole()->event);
    }

    public function test_reject_needs_a_reason_of_up_to_1000_characters(): void
    {
        $booking = $this->booking();

        $this->act('reject', $booking, ['reason' => ''])->assertSessionHasErrors('reason');
        $this->act('reject', $booking, ['reason' => str_repeat('a', 1001)])->assertSessionHasErrors('reason');
        $this->act('reject', $booking, ['reason' => 'Fully booked.'])->assertSessionHas('status');

        $this->assertSame('Fully booked.', $booking->fresh()->rejection_reason);
    }

    public function test_complete_is_shown_disabled_before_the_start_and_works_after(): void
    {
        $booking = $this->booking(Status::Confirmed, '2026-11-05 10:00');

        $this->actingAs($this->owner)->get(route('owner.bookings.show', $booking))->assertSee('Available from the start of the session');
        $this->act('complete', $booking)->assertSessionHas('error');
        $this->assertSame(Status::Confirmed, $booking->fresh()->status);

        $this->travelTo($this->manila('2026-11-05 10:05'));
        // Phase 5A: the photos are released only when the shoot is fully paid
        $this->act('complete', $booking)->assertSessionHas('error', PaymentException::RELEASE_MESSAGE);
        Payment::factory()->paying($booking->fresh())->create();
        $this->act('complete', $booking, ['note' => 'Great shoot.'])->assertSessionHas('status');
        $this->assertSame(Status::Completed, $booking->fresh()->status);
    }

    public function test_the_confirmed_price_can_change_with_from_and_to_in_the_history(): void
    {
        $booking = $this->booking(Status::Confirmed, attributes: ['confirmed_price' => '1000.00']);

        $this->act('price', $booking, ['confirmed_price' => 'abc'])->assertSessionHasErrors('confirmed_price');
        $this->act('price', $booking, ['confirmed_price' => '1250', 'reason' => 'Extra outfit.'])->assertSessionHas('status');

        $this->assertSame('1250.00', $booking->fresh()->confirmed_price);
        $this->assertSame('Confirmed price changed from ₱1,000.00 to ₱1,250.00. Extra outfit.', BookingStatusHistory::sole()->note);

        $request = $this->booking(manila: '2026-11-06 10:00');
        $this->act('price', $request, ['confirmed_price' => '5'])->assertSessionHas('error');
    }

    public function test_cancel_with_an_optional_reason(): void
    {
        $booking = $this->booking(Status::Confirmed);

        $this->act('cancel', $booking, ['reason' => 'Studio closed.'])->assertSessionHas('status');

        $this->assertSame(Status::Cancelled, $booking->fresh()->status);
        $this->assertSame('Studio closed.', $booking->fresh()->cancellation_reason);
        $this->act('cancel', $booking)->assertSessionHas('error');
        $this->assertSame(1, BookingStatusHistory::count());
    }

    public function test_internal_notes_are_saved_without_a_history_row(): void
    {
        $booking = $this->booking();

        $this->act('notes', $booking, ['internal_notes' => str_repeat('a', 2001)])->assertSessionHasErrors('internal_notes');
        $this->act('notes', $booking, ['internal_notes' => 'Wheelchair ramp needed.', 'status' => 'confirmed', 'confirmed_price' => '1'])->assertSessionHas('status');

        $fresh = $booking->fresh();
        $this->assertSame('Wheelchair ramp needed.', $fresh->internal_notes);
        $this->assertSame(Status::Requested, $fresh->status);
        $this->assertNull($fresh->confirmed_price);
        $this->assertSame(0, BookingStatusHistory::count());
    }

    public function test_a_stale_page_after_the_customer_acted_is_a_message(): void
    {
        $booking = $this->booking();
        app(BookingTransitionService::class)->cancel($booking, $this->customer);

        $this->act('confirm', $booking)->assertSessionHas('error');
        $this->act('reject', $booking, ['reason' => 'x'])->assertSessionHas('error');
        $this->assertSame(Status::Cancelled, $booking->fresh()->status);
        $this->assertSame(1, BookingStatusHistory::count());
    }

    public function test_hostile_text_is_escaped_for_the_owner(): void
    {
        $booking = $this->booking(attributes: ['notes' => '<script>alert(1)</script>', 'contact_number' => '<b>0917</b>'],
            customer: User::factory()->create(['name' => '<img src=x onerror=alert(2)>']));
        app(BookingTransitionService::class)->saveInternalNote($booking, $this->owner, '<script>alert(3)</script>');

        $html = $this->actingAs($this->owner)->get(route('owner.bookings.show', $booking))->getContent();

        foreach (['<script>alert(1)</script>', '<b>0917</b>', '<img src=x onerror', '<script>alert(3)</script>'] as $raw) {
            $this->assertStringNotContainsString($raw, $html);
        }

        $list = $this->actingAs($this->owner)->get(route('owner.bookings.index'))->getContent();
        $this->assertStringNotContainsString('<img src=x onerror', $list);
    }

    public function test_the_owner_navigation_links_to_the_bookings(): void
    {
        $this->actingAs($this->owner)->get(route('owner.dashboard'))->assertSee(route('owner.bookings.index'), false)->assertSee('Photography bookings');
    }
}
