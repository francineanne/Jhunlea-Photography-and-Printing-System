<?php

namespace Tests\Feature\Booking;

use App\Enums\BookingStatus;
use App\Models\BookingReferenceImage;
use App\Models\PhotographyBooking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BookingPoliciesTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-02 00:00:00', 'UTC'));
        $this->alice = User::factory()->create();
        $this->bob = User::factory()->create();
        $this->owner = User::factory()->owner()->create();
    }

    private function booking(BookingStatus $status = BookingStatus::Requested, ?Carbon $start = null): PhotographyBooking
    {
        return PhotographyBooking::factory()->forCustomer($this->alice)->status($status)->at($start ?? Carbon::parse('2026-11-10 02:00', 'UTC'))->create();
    }

    /** @return array<string, array{string, BookingStatus}> */
    public static function actions(): array
    {
        return [
            'view' => ['view', BookingStatus::Requested],
            'cancel a request' => ['cancel', BookingStatus::Requested],
            'respond to a proposal' => ['respondToProposal', BookingStatus::Proposed],
        ];
    }

    #[DataProvider('actions')]
    public function test_customer_b_can_never_act_on_customer_as_booking(string $ability, BookingStatus $status): void
    {
        $booking = $this->booking($status);

        $this->assertTrue(Gate::forUser($this->alice)->allows($ability, $booking));
        $this->assertFalse(Gate::forUser($this->bob)->allows($ability, $booking));
        $this->assertFalse(Gate::forUser($this->bob)->allows('changeStatus', $booking));
    }

    public function test_the_owner_sees_every_booking_and_does_the_studios_work(): void
    {
        $booking = $this->booking();

        $this->assertTrue(Gate::forUser($this->owner)->allows('view', $booking));
        $this->assertTrue(Gate::forUser($this->owner)->allows('changeStatus', $booking));
        $this->assertTrue(Gate::forUser($this->owner)->allows('cancel', $booking));
        $this->assertFalse(Gate::forUser($this->owner)->allows('respondToProposal', $this->booking(BookingStatus::Proposed)));
        $this->assertFalse(Gate::forUser($this->alice)->allows('changeStatus', $booking));
    }

    public function test_only_customers_request_bookings(): void
    {
        $this->assertTrue(Gate::forUser($this->alice)->allows('create', PhotographyBooking::class));
        $this->assertFalse(Gate::forUser($this->owner)->allows('create', PhotographyBooking::class));
    }

    public function test_a_customer_can_respond_only_while_a_time_is_proposed(): void
    {
        $this->assertFalse(Gate::forUser($this->alice)->allows('respondToProposal', $this->booking(BookingStatus::Requested)));
        $this->assertFalse(Gate::forUser($this->alice)->allows('respondToProposal', $this->booking(BookingStatus::Confirmed)));
    }

    public function test_the_cancel_cutoff_applies_to_confirmed_bookings_only(): void
    {
        config(['booking.customer_cancel_cutoff_hours' => 24]);
        $soon = Carbon::parse('2026-11-02 23:00', 'UTC');

        $this->assertFalse(Gate::forUser($this->alice)->allows('cancel', $this->booking(BookingStatus::Confirmed, $soon)));
        $this->assertTrue(Gate::forUser($this->alice)->allows('cancel', $this->booking(BookingStatus::Requested, $soon)));
        $this->assertTrue(Gate::forUser($this->alice)->allows('cancel', $this->booking(BookingStatus::Confirmed, Carbon::parse('2026-11-03 00:00', 'UTC'))));
        $this->assertFalse(Gate::forUser($this->alice)->allows('cancel', $this->booking(BookingStatus::Completed)));
        $this->assertTrue(Gate::forUser($this->owner)->allows('cancel', $this->booking(BookingStatus::Confirmed, $soon)));
    }

    public function test_a_reference_image_is_seen_only_by_its_customer_and_the_owner(): void
    {
        $image = BookingReferenceImage::factory()->for($this->booking(), 'booking')->create();

        $this->assertTrue(Gate::forUser($this->alice)->allows('view', $image));
        $this->assertTrue(Gate::forUser($this->owner)->allows('view', $image));
        $this->assertFalse(Gate::forUser($this->bob)->allows('view', $image));

        $image->booking->delete();
        $this->assertFalse(Gate::forUser($this->alice)->allows('view', $image->fresh()));
        $this->assertTrue(Gate::forUser($this->owner)->allows('view', $image->fresh()));
    }

    public function test_over_http_another_customer_gets_403_and_a_guest_is_sent_to_login(): void
    {
        Route::middleware(['web', 'auth', 'role:customer'])->get('/__test/bookings/{booking}', function (PhotographyBooking $booking) {
            Gate::authorize('view', $booking);

            return $booking->reference_number;
        });

        $booking = $this->booking();

        $this->actingAs($this->bob)->get("/__test/bookings/{$booking->id}")->assertForbidden();
        $this->actingAs($this->alice)->get("/__test/bookings/{$booking->id}")->assertOk()->assertSee($booking->reference_number);
        auth()->logout();
        $this->get("/__test/bookings/{$booking->id}")->assertRedirect('/login');
    }
}
