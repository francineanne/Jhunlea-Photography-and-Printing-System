<?php

namespace Tests\Feature\WalkIn;

use App\Enums\BookingStatus;
use App\Enums\RecordSource;
use App\Models\BookingStatusHistory;
use App\Models\PhotographyBooking;
use App\Models\PhotographyPackage;
use App\Models\User;
use App\Services\AvailabilityService;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Feature\Booking\SetsUpSchedule;
use Tests\TestCase;

/**
 * Phase 8A: a walk-in booking is saved confirmed under the schedule lock. A clash is never allowed; the minimum notice
 * and the advance limit do not apply at the counter; opening hours and blocked days apply unless "confirm anyway".
 * Now is Monday 2 November 2026, 08:00 in Manila; the studio opens 09:00 to 17:00, Monday to Saturday.
 */
class WalkInBookingTest extends TestCase
{
    use RefreshDatabase, SetsUpSchedule;

    private User $owner;

    private PhotographyPackage $package;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSchedule();
        $this->owner = User::factory()->owner()->create();
        $this->package = PhotographyPackage::factory()->published()->create(['name' => 'Solo Portrait', 'duration_minutes' => 60, 'max_people' => 3, 'price' => '1500.00', 'price_requires_confirmation' => false]);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'counter_token' => (string) Str::uuid(),
            'new_customer_name' => 'Lola Walk-in',
            'package' => $this->package->id,
            'custom_theme_text' => 'Plain white wall',
            'date' => '2026-11-02',
            'time' => '10:00',
            'group_size' => 2,
            'contact_number' => '0917 555 0101',
            'confirmed_price' => '1500',
        ], $overrides);
    }

    private function send(array $payload)
    {
        return $this->actingAs($this->owner)->post(route('owner.bookings.walk-in.store'), $payload);
    }

    private function confirmedAt(string $local): PhotographyBooking
    {
        return PhotographyBooking::factory()->status(BookingStatus::Confirmed)->at($this->manila($local), 60)->create(['photography_package_id' => $this->package->id]);
    }

    public function test_a_walk_in_booking_inside_the_notice_window_is_saved_confirmed(): void
    {
        // 10:00 today is only 2 hours away: fine at the counter (the online minimum notice is 24 hours)
        $this->send($this->payload())->assertSessionHasNoErrors();

        $booking = PhotographyBooking::query()->sole();
        $this->assertSame(RecordSource::WalkIn, $booking->source);
        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertSame('1500.00', $booking->confirmed_price);
        $this->assertTrue($booking->starts_at->eq($this->manila('2026-11-02 10:00')));
        $this->assertTrue($booking->customer->isWalkIn());

        $history = $booking->statusHistories()->sole();
        $this->assertNull($history->from_status);
        $this->assertSame(BookingStatus::Confirmed, $history->to_status);
        $this->assertStringContainsString('Created at the counter', $history->note);
        $this->assertSame(0, DB::table('notifications')->count());

        $this->actingAs($this->owner)->get(route('owner.bookings.show', $booking))->assertOk()->assertSee('Walk-in');
    }

    public function test_the_price_is_optional_and_set_later(): void
    {
        $this->send($this->payload(['confirmed_price' => '']))->assertSessionHasNoErrors();
        $this->assertNull(PhotographyBooking::query()->sole()->confirmed_price);

        $this->send($this->payload(['confirmed_price' => 'a lot', 'time' => '13:00']))->assertSessionHasErrors('confirmed_price');
    }

    public function test_a_clash_with_a_confirmed_booking_is_never_allowed(): void
    {
        $this->confirmedAt('2026-11-03 10:00');

        foreach (['10:00', '10:30'] as $time) {
            $this->send($this->payload(['date' => '2026-11-03', 'time' => $time, 'outside_schedule' => '1']))
                ->assertSessionHasErrors(['starts_at' => 'This time is no longer free: another confirmed session is booked then. Propose another time instead.']);
        }

        $this->assertSame(1, PhotographyBooking::query()->count());
        $this->send($this->payload(['date' => '2026-11-03', 'time' => '11:00']))->assertSessionHasNoErrors();
        $this->assertSame(2, PhotographyBooking::query()->count());
    }

    public function test_a_walk_in_booking_makes_the_time_busy_for_online_customers(): void
    {
        $free = fn () => app(AvailabilityService::class)->slots(60, '2026-11-05', '2026-11-05')->map(fn ($slot) => $slot->startsAt->copy()->setTimezone('Asia/Manila')->format('H:i'))->all();
        $this->assertContains('14:00', $free());

        $this->send($this->payload(['date' => '2026-11-05', 'time' => '14:00']))->assertSessionHasNoErrors();

        $this->assertNotContains('14:00', $free());
        $this->assertNotContains('13:30', $free());

        // And the online booking itself is refused for that time
        $this->expectException(ValidationException::class);
        app(BookingService::class)->place(User::factory()->create(), $this->package, null, 'Idea', $this->manila('2026-11-05 14:00'), 1, '0917 123 4567', null, false);
    }

    public function test_the_past_and_the_schedule_rules(): void
    {
        $this->send($this->payload(['time' => '07:00', 'date' => '2026-11-01']))->assertSessionHasErrors('starts_at');

        // Sunday is closed: refused, unless the owner confirms anyway
        $this->send($this->payload(['date' => '2026-11-08']))->assertSessionHasErrors('starts_at');
        $this->assertSame(0, PhotographyBooking::query()->count());
        $this->send($this->payload(['date' => '2026-11-08', 'outside_schedule' => '1']))->assertSessionHasNoErrors();
        $this->assertStringContainsString('Confirmed outside the usual schedule', PhotographyBooking::query()->sole()->statusHistories()->first()->note);

        // Beyond the 30-day advance limit is fine at the counter
        $this->send($this->payload(['date' => '2026-12-21']))->assertSessionHasNoErrors();
    }

    public function test_a_failure_after_the_insert_leaves_nothing_behind(): void
    {
        BookingStatusHistory::creating(fn () => throw new RuntimeException('Lost the database.'));
        $this->withoutExceptionHandling();

        try {
            $this->send($this->payload());
            $this->fail('The failure did not reach the test.');
        } catch (RuntimeException) {
        }

        $this->assertSame(0, PhotographyBooking::query()->count());
        $this->assertSame(0, BookingStatusHistory::query()->count());
        $this->assertSame(0, User::query()->where('is_walk_in', true)->count());
    }

    public function test_a_double_submit_saves_one_booking(): void
    {
        $payload = $this->payload();
        $this->send($payload);
        $this->send($payload);

        $this->assertSame(1, PhotographyBooking::query()->count());
        $this->assertSame(1, User::query()->where('is_walk_in', true)->count());
    }

    public function test_the_owners_free_times_skip_the_notice_and_busy_times(): void
    {
        $this->confirmedAt('2026-11-02 11:00');

        $times = $this->actingAs($this->owner)->getJson(route('owner.bookings.walk-in.slots', ['package' => $this->package->id, 'date' => '2026-11-02']))->assertOk()->json('times');
        $values = array_column($times, 'value');
        $this->assertSame('09:00', $values[0]);
        $this->assertNotContains('11:00', $values);
        $this->assertSame([], $this->actingAs($this->owner)->getJson(route('owner.bookings.walk-in.slots', ['package' => $this->package->id, 'date' => '2026-11-08']))->json('times'));
    }

    public function test_only_the_owner_reaches_it_and_the_lists_show_the_badge(): void
    {
        $customer = User::factory()->create();
        foreach ([['get', route('owner.bookings.walk-in.create')], ['post', route('owner.bookings.walk-in.store')], ['get', route('owner.bookings.walk-in.slots')]] as [$method, $url]) {
            $this->actingAs($customer)->{$method}($url, $this->payload())->assertForbidden();
            auth()->logout();
            $this->{$method}($url)->assertRedirect(route('login'));
        }
        $this->assertSame(0, PhotographyBooking::query()->count());

        $walkIn = PhotographyBooking::factory()->walkIn()->status(BookingStatus::Confirmed)->at($this->manila('2026-11-04 10:00'), 60)->create(['reference_number' => 'PB-800001']);
        PhotographyBooking::factory()->status(BookingStatus::Confirmed)->at($this->manila('2026-11-04 13:00'), 60)->create(['reference_number' => 'PB-800002']);

        $this->actingAs($this->owner)->get(route('owner.bookings.walk-in.create'))->assertOk()->assertSee('Solo Portrait');
        $this->actingAs($this->owner)->get(route('owner.bookings.index', ['status' => 'all']))->assertOk()->assertSee('PB-800001')->assertSee('Walk-in')->assertSee('New walk-in booking');
        $this->actingAs($this->owner)->get(route('owner.bookings.index', ['status' => 'all', 'source' => 'walk_in']))->assertSee('PB-800001')->assertDontSee('PB-800002');
        $this->actingAs($this->owner)->get(route('owner.schedule.index', ['view' => 'week', 'date' => '2026-11-04']))->assertOk()->assertSee('PB-800001 · Confirmed · Walk-in', false)->assertSee('New walk-in booking');
        $this->assertTrue($walkIn->isWalkIn());
    }
}
