<?php

namespace Tests\Feature\Booking;

use App\Enums\BookingEvent;
use App\Enums\BookingStatus as Status;
use App\Models\BlockedDate;
use App\Models\BookingReferenceImage;
use App\Models\BookingStatusHistory;
use App\Models\PhotographyBooking;
use App\Models\User;
use App\Services\BookingTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Phase 4C: the customer's own bookings, one booking, cancelling and answering a proposed time. */
class MyBookingsTest extends TestCase
{
    use RefreshDatabase, SetsUpSchedule;

    private User $customer;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSchedule();
        $this->customer = User::factory()->create(['name' => 'Ana Customer']);
        $this->owner = User::factory()->owner()->create(['name' => 'Studio Owner Name', 'email' => 'owner-secret@example.test']);
    }

    private function booking(Status $status = Status::Requested, string $manila = '2026-11-05 10:00', ?User $customer = null, array $attributes = []): PhotographyBooking
    {
        return PhotographyBooking::factory()->forCustomer($customer ?? $this->customer)->status($status)->at($this->manila($manila))->create($attributes);
    }

    private function proposed(string $requested = '2026-11-05 10:00', string $offered = '2026-11-06 14:00'): PhotographyBooking
    {
        $booking = $this->booking(Status::Requested, $requested);
        app(BookingTransitionService::class)->propose($booking, $this->owner, $this->manila($offered), 'Would 2 PM work?');

        return $booking->fresh();
    }

    // Access

    public function test_guests_go_to_login_and_the_owner_is_refused(): void
    {
        $booking = $this->booking();

        foreach ([route('customer.bookings.index'), route('customer.bookings.show', $booking)] as $url) {
            $this->get($url)->assertRedirect(route('login'));
            $this->actingAs($this->owner)->get($url)->assertForbidden();
            auth()->logout();
        }

        foreach (['cancel', 'accept', 'another-time'] as $action) {
            $this->post(route("customer.bookings.{$action}", $booking))->assertRedirect(route('login'));
            $this->actingAs($this->owner)->post(route("customer.bookings.{$action}", $booking), ['note' => 'x'])->assertForbidden();
            auth()->logout();
        }

        $this->assertSame(0, BookingStatusHistory::count());
    }

    public function test_another_customer_cannot_see_cancel_or_answer_the_booking(): void
    {
        $booking = $this->proposed();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get(route('customer.bookings.show', $booking))->assertForbidden();
        $this->actingAs($stranger)->post(route('customer.bookings.cancel', $booking))->assertForbidden();
        $this->actingAs($stranger)->post(route('customer.bookings.accept', $booking))->assertForbidden();
        $this->actingAs($stranger)->post(route('customer.bookings.another-time', $booking), ['note' => 'Me?'])->assertForbidden();

        $this->assertSame(Status::Proposed, $booking->fresh()->status);
        $this->assertSame(1, BookingStatusHistory::count());
        $this->actingAs($stranger)->get(route('customer.bookings.index'))->assertDontSee($booking->reference_number);
    }

    // The list

    public function test_the_list_shows_only_my_bookings_upcoming_confirmed_first(): void
    {
        $this->travelTo($this->manila('2026-11-02 08:00'));
        $later = $this->booking(Status::Confirmed, '2026-11-20 10:00');
        $sooner = $this->booking(Status::Confirmed, '2026-11-10 10:00');
        $past = $this->booking(Status::Completed, '2026-10-01 10:00');
        $this->travel(1)->minutes();
        $request = $this->booking(Status::Requested, '2026-11-12 10:00');
        $this->travel(1)->minutes();
        $newest = $this->booking(Status::Cancelled, '2026-11-13 10:00');
        $theirs = $this->booking(Status::Confirmed, '2026-11-03 10:00', User::factory()->create());

        $html = $this->actingAs($this->customer)->get(route('customer.bookings.index'))->assertOk()->getContent();

        $order = collect([$sooner, $later, $newest, $request, $past])->map(fn ($b) => strpos($html, $b->reference_number))->all();
        $this->assertSame($order, collect($order)->sort()->values()->all(), 'Upcoming confirmed first by start, then newest first');
        $this->assertStringNotContainsString($theirs->reference_number, $html);
    }

    public function test_the_status_filter_and_its_counts(): void
    {
        $this->booking(Status::Requested);
        $confirmed = $this->booking(Status::Confirmed, '2026-11-06 10:00');

        $this->actingAs($this->customer)->get(route('customer.bookings.index', ['status' => 'confirmed']))
            ->assertOk()
            ->assertSee($confirmed->reference_number)
            ->assertSeeInOrder(['Requested', '1', 'New time proposed', '0', 'Confirmed', '1']);

        $this->actingAs($this->customer)->get(route('customer.bookings.index', ['status' => 'nope']))
            ->assertRedirect(route('customer.bookings.index'))->assertSessionHasErrors('status');
        $this->actingAs($this->customer)->get(route('customer.bookings.index', ['status' => ['confirmed']]))
            ->assertRedirect(route('customer.bookings.index'));
    }

    public function test_an_empty_list_offers_to_book(): void
    {
        $this->actingAs($this->customer)->get(route('customer.bookings.index'))
            ->assertSee('No bookings yet')
            ->assertSee(route('customer.bookings.create'), false);
    }

    public function test_the_list_is_paginated(): void
    {
        PhotographyBooking::factory()->forCustomer($this->customer)->count(12)->create();

        $this->actingAs($this->customer)->get(route('customer.bookings.index'))->assertSee('page=2', false);
    }

    // One booking

    public function test_the_detail_shows_the_request_and_a_customer_timeline(): void
    {
        $booking = $this->booking(attributes: ['notes' => 'Bringing a toga.', 'custom_theme_text' => 'Garden look', 'contact_number' => '0917 555 0142', 'group_size' => 2, 'wants_prints' => true]);
        app(BookingTransitionService::class)->recordRequest($booking, $this->customer);
        app(BookingTransitionService::class)->confirm($booking, $this->owner, null, 'See you then.');

        $this->actingAs($this->customer)->get(route('customer.bookings.show', $booking))->assertOk()
            ->assertSee($booking->reference_number)
            ->assertSee('Sample package')
            ->assertSee('Bringing a toga.')
            ->assertSee('Garden look')
            ->assertSee('0917 555 0142')
            ->assertSee('2 people')
            ->assertSee('May want prints after the shoot')
            ->assertSee('₱1,000.00')
            ->assertSee('Thu, Nov 5, 2026')
            ->assertSee('10:00 AM')
            ->assertSee('Matches the request')
            ->assertSee('You')
            ->assertSee('The shop')
            ->assertSee('See you then.')
            ->assertDontSee('Studio Owner Name')
            ->assertDontSee('owner-secret@example.test')
            ->assertSee('A request cannot be edited. To change it, cancel it and send a new one.');
    }

    public function test_internal_notes_never_reach_the_customer(): void
    {
        $booking = $this->proposed();
        app(BookingTransitionService::class)->saveInternalNote($booking, $this->owner, 'SECRET: owes for the last shoot');

        foreach ([route('customer.bookings.show', $booking), route('customer.bookings.index'), route('customer.bookings.index', ['status' => 'proposed']), route('customer.dashboard')] as $url) {
            $this->actingAs($this->customer)->get($url)->assertOk()->assertDontSee('SECRET: owes for the last shoot');
        }

        $this->actingAs($this->customer)->getJson(route('customer.bookings.slots', ['package' => $booking->photography_package_id, 'from' => '2026-11-05', 'to' => '2026-11-06']))
            ->assertDontSee('SECRET');
        $this->assertStringNotContainsString('SECRET', $booking->fresh()->toJson());
        $this->assertSame(0, BookingStatusHistory::query()->where('note', 'like', '%SECRET%')->count());
    }

    public function test_rejection_and_cancellation_reasons_are_shown(): void
    {
        $rejected = $this->booking();
        app(BookingTransitionService::class)->reject($rejected, $this->owner, 'Fully booked that week.');
        $cancelled = $this->booking(manila: '2026-11-06 10:00');
        app(BookingTransitionService::class)->cancel($cancelled, $this->owner, 'Studio closed for repairs.');

        $this->actingAs($this->customer)->get(route('customer.bookings.show', $rejected))->assertSee('Fully booked that week.')->assertSee('The studio could not take this booking');
        $this->actingAs($this->customer)->get(route('customer.bookings.show', $cancelled))->assertSee('Studio closed for repairs.')->assertSee('Reason for cancelling');
    }

    public function test_hostile_text_is_escaped(): void
    {
        $booking = $this->booking(attributes: ['notes' => '<script>alert(1)</script>', 'custom_theme_text' => '<b>bold</b>', 'contact_number' => '<i>0917</i>']);
        BookingReferenceImage::factory()->create(['photography_booking_id' => $booking->id, 'original_name' => '<img src=x onerror=alert(2)>.jpg']);
        app(BookingTransitionService::class)->reject($booking, $this->owner, '<script>alert(3)</script>');

        $html = $this->actingAs($this->customer)->get(route('customer.bookings.show', $booking))->getContent();

        foreach (['<script>alert(1)</script>', '<b>bold</b>', '<i>0917</i>', '<img src=x onerror', '<script>alert(3)</script>'] as $raw) {
            $this->assertStringNotContainsString($raw, $html);
        }
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    // Answering a proposal

    public function test_the_proposal_card_and_accepting_it(): void
    {
        $booking = $this->proposed();

        $this->actingAs($this->customer)->get(route('customer.bookings.show', $booking))
            ->assertSee('The studio offered a new time')
            ->assertSee('Would 2 PM work?')
            ->assertSee('Accept the new time')
            ->assertSee('Ask for another time');

        $this->actingAs($this->customer)->post(route('customer.bookings.accept', $booking))
            ->assertRedirect(route('customer.bookings.show', $booking))
            ->assertSessionHas('status');

        $fresh = $booking->fresh();
        $this->assertSame(Status::Confirmed, $fresh->status);
        $this->assertSame('2026-11-06 06:00:00', $fresh->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame(BookingEvent::ProposalAccepted, BookingStatusHistory::query()->latest('id')->first()->event);
    }

    public function test_the_proposal_card_always_shows_the_price_that_applies_on_accepting(): void
    {
        // Estimate only
        $estimated = $this->proposed();
        $this->actingAs($this->customer)->get(route('customer.bookings.show', $estimated))
            ->assertSee('If you accept · Estimated price')->assertSee('₱1,000.00')->assertSee('The studio confirms the final price.');

        // A price the studio named with the proposal
        $named = $this->booking(manila: '2026-11-07 10:00');
        app(BookingTransitionService::class)->propose($named, $this->owner, $this->manila('2026-11-07 14:00'), 'Afternoon?', '1500');
        $this->actingAs($this->customer)->get(route('customer.bookings.show', $named))
            ->assertSee('If you accept · Confirmed price')->assertSee('₱1,500.00');

        // No price anywhere yet (a priced-on-request package always gets one with the proposal, so set up directly)
        $none = $this->booking(Status::Proposed, '2026-11-09 10:00', attributes: ['estimated_price' => null, 'proposed_starts_at' => $this->manila('2026-11-09 14:00')]);
        $this->actingAs($this->customer)->get(route('customer.bookings.show', $none))
            ->assertSee('If you accept · Price')->assertSee('Price confirmed by the studio');
    }

    public function test_asking_for_another_time_needs_a_note_of_up_to_500_characters(): void
    {
        $booking = $this->proposed();

        $this->actingAs($this->customer)->post(route('customer.bookings.another-time', $booking), ['note' => '   '])->assertSessionHasErrors('note');
        $this->actingAs($this->customer)->post(route('customer.bookings.another-time', $booking), ['note' => str_repeat('a', 501)])->assertSessionHasErrors('note');
        $this->assertSame(Status::Proposed, $booking->fresh()->status);

        $this->actingAs($this->customer)->post(route('customer.bookings.another-time', $booking), ['note' => 'Only mornings, please.'])->assertSessionHas('status');

        $this->assertSame(Status::Requested, $booking->fresh()->status);
        $this->assertSame('Only mornings, please.', BookingStatusHistory::query()->latest('id')->first()->note);
    }

    public function test_a_proposed_time_taken_meanwhile_leaves_the_booking_proposed_with_a_friendly_message(): void
    {
        $booking = $this->proposed();
        PhotographyBooking::factory()->status(Status::Confirmed)->at($this->manila('2026-11-06 14:00'))->create();

        $this->actingAs($this->customer)->post(route('customer.bookings.accept', $booking))
            ->assertSessionHas('error', 'That time is no longer free, so your booking was not changed. You can ask the studio for another time below.');

        $this->assertSame(Status::Proposed, $booking->fresh()->status);
    }

    public function test_accepting_rechecks_the_schedule_rules(): void
    {
        $booking = $this->proposed();
        BlockedDate::factory()->create(['date' => '2026-11-06']);

        $this->actingAs($this->customer)->post(route('customer.bookings.accept', $booking))
            ->assertSessionHas('error', 'That time is no longer free, so your booking was not changed. You can ask the studio for another time below.');

        $this->assertSame(Status::Proposed, $booking->fresh()->status);
    }

    public function test_answering_a_proposal_that_the_studio_already_withdrew_is_a_message(): void
    {
        $booking = $this->proposed();
        app(BookingTransitionService::class)->reject($booking, $this->owner, 'No longer possible.');

        $this->actingAs($this->customer)->post(route('customer.bookings.accept', $booking))->assertSessionHas('error');
        $this->actingAs($this->customer)->post(route('customer.bookings.another-time', $booking), ['note' => 'Hi'])->assertSessionHas('error');

        $this->assertSame(Status::Rejected, $booking->fresh()->status);
        $this->assertSame(2, BookingStatusHistory::count());
    }

    // Cancelling

    public function test_a_request_is_cancelled_with_an_optional_reason_and_a_double_click_writes_once(): void
    {
        $booking = $this->booking();

        $this->actingAs($this->customer)->post(route('customer.bookings.cancel', $booking), ['reason' => str_repeat('a', 501)])->assertSessionHasErrors('reason');
        $this->actingAs($this->customer)->post(route('customer.bookings.cancel', $booking), ['reason' => 'Change of plans.'])->assertSessionHas('status');
        $this->actingAs($this->customer)->post(route('customer.bookings.cancel', $booking), ['reason' => 'Again'])->assertSessionHas('error');

        $this->assertSame(Status::Cancelled, $booking->fresh()->status);
        $this->assertSame('Change of plans.', $booking->fresh()->cancellation_reason);
        $this->assertSame(1, BookingStatusHistory::count());
    }

    public function test_a_confirmed_booking_inside_the_cutoff_cannot_be_cancelled_here(): void
    {
        config(['booking.customer_cancel_cutoff_hours' => 36]);
        $soon = $this->booking(Status::Confirmed, '2026-11-03 10:00');

        $this->actingAs($this->customer)->get(route('customer.bookings.show', $soon))
            ->assertDontSee('Cancel this booking')
            ->assertSee('A confirmed booking can be cancelled here until 36 hours before it starts', false)
            ->assertSee('Please talk to the studio');

        $this->actingAs($this->customer)->post(route('customer.bookings.cancel', $soon))
            ->assertSessionHas('error', 'A confirmed booking can be cancelled here until 36 hours before it starts. Please talk to the studio.');
        $this->assertSame(Status::Confirmed, $soon->fresh()->status);

        $later = $this->booking(Status::Confirmed, '2026-11-20 10:00');
        $this->actingAs($this->customer)->get(route('customer.bookings.show', $later))->assertSee('Cancel this booking');
    }

    public function test_extra_fields_cannot_change_status_price_times_or_owner(): void
    {
        $booking = $this->booking();
        $other = User::factory()->create();

        $this->actingAs($this->customer)->post(route('customer.bookings.cancel', $booking), [
            'status' => 'confirmed', 'confirmed_price' => '1', 'starts_at' => '2030-01-01 00:00:00', 'customer_id' => $other->id, 'internal_notes' => 'x',
        ]);

        $fresh = $booking->fresh();
        $this->assertSame(Status::Cancelled, $fresh->status);
        $this->assertNull($fresh->confirmed_price);
        $this->assertSame($this->customer->id, $fresh->customer_id);
        $this->assertSame('2026-11-05 02:00:00', $fresh->starts_at->format('Y-m-d H:i:s'));
        $this->assertNull($fresh->internal_notes);
    }

    public function test_the_dashboard_links_to_my_bookings_and_flags_a_proposal(): void
    {
        $this->proposed();

        $this->actingAs($this->customer)->get(route('customer.dashboard'))
            ->assertSee(route('customer.bookings.index'), false)
            ->assertSee('The studio offered a new time for 1 booking.');
    }
}
