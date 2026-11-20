<?php

namespace Tests\Feature\Booking;

use App\Enums\BookingStatus;
use App\Models\PhotographyBooking;
use App\Models\User;
use App\Services\Assistant\Owner\OwnerTools;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 10L: a request whose time passed without an answer (the derived "Expired" state of 10A) is listed and counted
 * under its own Expired tab, on the owner's list and the customer's "My bookings", never under Requested or New time
 * proposed; "All" still includes it. "Now" is Monday 2 November 2026, 08:00 in Manila.
 */
class ExpiredTabTest extends TestCase
{
    use RefreshDatabase, SetsUpSchedule;

    private User $owner;

    private User $ana;

    private PhotographyBooking $open;

    private PhotographyBooking $expiredRequest;

    private PhotographyBooking $expiredProposal;

    private PhotographyBooking $offer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSchedule();
        $this->owner = User::factory()->owner()->create();
        $this->ana = User::factory()->create(['name' => 'Ana Cruz']);
        $this->open = PhotographyBooking::factory()->status(BookingStatus::Requested)->forCustomer($this->ana)->at($this->manila('2026-11-20 10:00'))->create();
        // Asked for a time that has now passed, nobody answered
        $this->expiredRequest = PhotographyBooking::factory()->status(BookingStatus::Requested)->forCustomer($this->ana)->at($this->manila('2026-10-30 10:00'))->create();
        // The studio offered a time that has passed, the customer never answered
        $this->expiredProposal = PhotographyBooking::factory()->status(BookingStatus::Proposed)->forCustomer($this->ana)->at($this->manila('2026-10-29 10:00'))
            ->create(['proposed_starts_at' => $this->manila('2026-10-31 10:00')]);
        $this->offer = PhotographyBooking::factory()->status(BookingStatus::Proposed)->forCustomer($this->ana)->at($this->manila('2026-11-18 10:00'))
            ->create(['proposed_starts_at' => $this->manila('2026-11-19 10:00')]);
        PhotographyBooking::factory()->status(BookingStatus::Confirmed)->forCustomer($this->ana)->at($this->manila('2026-11-21 10:00'))->create(['confirmed_price' => '1500.00']);
    }

    public function test_the_owner_list_puts_expired_requests_in_their_own_tab(): void
    {
        $this->actingAs($this->owner);

        $requested = $this->get(route('owner.bookings.index', ['status' => 'requested']))->assertOk()
            ->assertSee($this->open->reference_number)->assertDontSee($this->expiredRequest->reference_number);
        $requested->assertViewHas('counts', fn ($counts) => $counts['requested'] === 1 && $counts['proposed'] === 1 && $counts['expired'] === 2 && $counts->sum() === 5);
        $requested->assertViewHas('expiredCount', 2);

        $this->get(route('owner.bookings.index', ['status' => 'proposed']))->assertOk()
            ->assertSee($this->offer->reference_number)->assertDontSee($this->expiredProposal->reference_number);

        $this->get(route('owner.bookings.index', ['status' => 'all', 'expired' => 1]))->assertOk()
            ->assertSee($this->expiredRequest->reference_number)->assertSee($this->expiredProposal->reference_number)
            ->assertDontSee($this->open->reference_number)
            // Only "Expired": no payment pill on an expired request
            ->assertDontSee('Awaiting price');

        $this->get(route('owner.bookings.index', ['status' => 'all']))->assertOk()
            ->assertSee($this->expiredRequest->reference_number)->assertSee($this->open->reference_number);
    }

    public function test_the_intro_says_how_long_a_request_holds_its_time(): void
    {
        $this->actingAs($this->owner)->get(route('owner.bookings.index'))->assertOk()
            ->assertSee('A new request holds its time for 48 hours')
            ->assertDontSee('Requests do not hold a time until you confirm them');
    }

    public function test_the_dashboard_and_lumi_count_only_requests_still_waiting(): void
    {
        $overview = app(DashboardService::class)->owner($this->owner);
        $this->assertSame(1, $overview['requestCount']);
        $this->assertSame([$this->open->id], $overview['requests']->pluck('id')->all());

        $waiting = app(OwnerTools::class)->get('bookings_schedule')->execute(['range' => 'all_time', 'status' => 'waiting'], $this->owner);
        $this->assertSame(2, $waiting->total, 'The open request and the open offer; the expired ones are not waiting');
    }

    public function test_the_customer_sees_an_expired_tab_with_a_plain_sentence(): void
    {
        $this->actingAs($this->ana);

        $this->get(route('customer.bookings.index', ['status' => 'requested']))->assertOk()
            ->assertSee($this->open->reference_number)->assertDontSee($this->expiredRequest->reference_number)
            ->assertViewHas('counts', fn ($counts) => $counts['requested'] === 1 && $counts['expired'] === 2 && $counts->sum() === 5);

        $this->get(route('customer.bookings.index', ['status' => 'expired']))->assertOk()
            ->assertSee($this->expiredRequest->reference_number)->assertSee($this->expiredProposal->reference_number)
            ->assertDontSee($this->offer->reference_number)
            ->assertSee(PhotographyBooking::EXPIRED_CUSTOMER_TEXT)
            ->assertDontSee('Awaiting price');

        $this->get(route('customer.bookings.index'))->assertOk()->assertSee($this->expiredRequest->reference_number);
        $this->get(route('customer.bookings.index', ['status' => 'nonsense']))->assertRedirect(route('customer.bookings.index'));
    }

    public function test_one_time_wording_per_status_and_the_walk_in_badge_sits_with_the_name(): void
    {
        $walkIn = User::factory()->walkIn()->create(['name' => 'Juan Dela Cruz', 'phone' => '09914710187']);
        $awaiting = PhotographyBooking::factory()->status(BookingStatus::AwaitingDownpayment)->forCustomer($walkIn)->walkIn()->at($this->manila('2026-11-23 12:00'))
            ->create(['confirmed_price' => '1200.00', 'contact_number' => '09914710187', 'downpayment_due_at' => $this->manila('2026-11-20 12:00')]);

        $this->assertSame(['Booked', 'Waiting for downpayment'], [$awaiting->timeLine()['label'], $awaiting->timeLine()['note']]);
        $this->assertSame(['Asked', 'Not confirmed'], [$this->open->timeLine()['label'], $this->open->timeLine()['note']]);
        $this->assertSame(['Offered', 'Waiting for the customer'], [$this->offer->timeLine()['label'], $this->offer->timeLine()['note']]);
        $this->assertTrue($this->offer->timeLine()['at']->eq($this->offer->proposed_starts_at));
        $this->assertSame(['Asked', 'Time passed, not answered'], [$this->expiredRequest->timeLine()['label'], $this->expiredRequest->timeLine()['note']]);
        $this->assertSame(['Asked', 'Time passed, not answered'], [$this->expiredProposal->timeLine()['label'], $this->expiredProposal->timeLine()['note']]);

        $html = $this->actingAs($this->owner)->get(route('owner.bookings.index', ['status' => 'awaiting_downpayment']))->assertOk()
            ->assertSee('Waiting for downpayment')->assertSee('0991 471 0187')->getContent();
        // In the table the badge follows the customer's name, not the reference
        $row = substr($html, strpos($html, '<tbody'));
        $this->assertLessThan(strpos($row, 'storefront'), strpos($row, 'Juan Dela Cruz'));
        $this->assertStringNotContainsString('Not confirmed', $row, 'A booked walk-in session never reads "Not confirmed"');
    }
}
