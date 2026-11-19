<?php

namespace Tests\Feature\WalkIn;

use App\Enums\BookingStatus;
use App\Enums\RecordSource;
use App\Exceptions\BookingTransitionException;
use App\Models\BookingStatusHistory;
use App\Models\PhotographyBooking;
use App\Models\PhotographyPackage;
use App\Models\User;
use App\Services\AvailabilityService;
use App\Services\BookingService;
use App\Services\WalkIn\WalkInBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Feature\Booking\SetsUpSchedule;
use Tests\Support\SetsPaymentRules;
use Tests\TestCase;

/**
 * Phase 8A: a walk-in booking is saved confirmed under the schedule lock. A clash is never allowed; the advance limit
 * does not apply at the counter; opening hours and blocked days apply unless "confirm anyway". Since Phase 10I the
 * minimum notice (whole days, never same-day) applies at the counter too and is never set aside.
 * Now is Monday 2 November 2026, 08:00 in Manila; the studio opens 09:00 to 17:00, Monday to Saturday.
 */
class WalkInBookingTest extends TestCase
{
    use RefreshDatabase, SetsPaymentRules, SetsUpSchedule;

    private User $owner;

    private PhotographyPackage $package;

    protected function setUp(): void
    {
        parent::setUp();

        // Written before Phase 10B: no downpayment, printing paid at pickup (the new rules have their own tests)
        $this->withoutDownpaymentsOrPrepayment();

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
            // Phase 10I: tomorrow (same-day photoshoots are never allowed, at the counter too; this was today)
            'date' => '2026-11-03',
            'time' => '10:00',
            'group_size' => 2,
            'new_customer_phone' => '0917 555 0101',
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

    // Phase 10I: was "inside the notice window" (10:00 today); the counter now follows the same notice as online
    public function test_a_walk_in_booking_for_tomorrow_is_saved_confirmed(): void
    {
        $this->send($this->payload())->assertSessionHasNoErrors();

        $booking = PhotographyBooking::query()->sole();
        $this->assertSame(RecordSource::WalkIn, $booking->source);
        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertSame('1500.00', $booking->confirmed_price);
        $this->assertTrue($booking->starts_at->eq($this->manila('2026-11-03 10:00')));
        $this->assertTrue($booking->customer->isWalkIn());

        $history = $booking->statusHistories()->sole();
        $this->assertNull($history->from_status);
        $this->assertSame(BookingStatus::Confirmed, $history->to_status);
        $this->assertStringContainsString('Created at the counter', $history->note);
        $this->assertSame(0, DB::table('notifications')->count());

        $this->actingAs($this->owner)->get(route('owner.bookings.show', $booking))->assertOk()->assertSee('Walk-in');
    }

    // Phase 10J: was "the price is optional and set later": at the counter the price is required (0 = free)
    public function test_the_price_is_required_at_the_counter(): void
    {
        $this->send($this->payload(['confirmed_price' => '']))->assertSessionHasErrors(['confirmed_price' => WalkInBookingService::PRICE_REQUIRED]);
        $this->send($this->payload(['confirmed_price' => 'a lot']))->assertSessionHasErrors('confirmed_price');
        $this->assertSame(0, PhotographyBooking::query()->count());

        $this->send($this->payload(['confirmed_price' => '0']))->assertSessionHasNoErrors();
        $this->assertSame('0.00', PhotographyBooking::query()->sole()->confirmed_price);
    }

    public function test_a_clash_with_a_confirmed_booking_is_never_allowed(): void
    {
        $this->confirmedAt('2026-11-03 10:00');

        foreach (['10:00', '10:30'] as $time) {
            $this->send($this->payload(['date' => '2026-11-03', 'time' => $time, 'outside_schedule' => '1']))
                ->assertSessionHasErrors(['starts_at' => BookingTransitionException::conflict()->getMessage()]);
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

        // Phase 10J: the online rules with no override (was "refused unless the owner confirms anyway" and "beyond the
        // advance limit is fine at the counter"): Sunday is closed, with or without the old tick
        $this->send($this->payload(['date' => '2026-11-08']))->assertSessionHasErrors('starts_at');
        $this->send($this->payload(['date' => '2026-11-08', 'outside_schedule' => '1']))->assertSessionHasErrors('starts_at');
        // Beyond the 30-day advance window is refused too, as online
        $this->send($this->payload(['date' => '2026-12-21']))->assertSessionHasErrors('starts_at');
        $this->assertSame(0, PhotographyBooking::query()->count());
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

    // Phase 10I: was "skip the notice": the counter's free times follow the same notice as online (none today)
    public function test_the_owners_free_times_follow_the_notice_and_skip_busy_times(): void
    {
        $this->confirmedAt('2026-11-03 11:00');

        $slots = fn (string $date) => $this->actingAs($this->owner)->getJson(route('owner.bookings.walk-in.slots', ['package' => $this->package->id, 'date' => $date]))->assertOk();
        $this->assertSame([], $slots('2026-11-02')->json('times'));
        $this->assertSame('Same-day photoshoots are not allowed. Choose tomorrow or later.', $slots('2026-11-02')->json('day_text'));

        $values = array_column($slots('2026-11-03')->json('times'), 'value');
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
        // Phase 10J: the list links to the one Walk-in area instead of its own "New walk-in booking" button
        $this->actingAs($this->owner)->get(route('owner.bookings.index', ['status' => 'all']))->assertOk()->assertSee('PB-800001')->assertSee('Walk-in')->assertDontSee('New walk-in booking')->assertSee(route('owner.walk-in.index'), false);
        $this->actingAs($this->owner)->get(route('owner.bookings.index', ['status' => 'all', 'source' => 'walk_in']))->assertSee('PB-800001')->assertDontSee('PB-800002');
        $this->actingAs($this->owner)->get(route('owner.schedule.index', ['view' => 'week', 'date' => '2026-11-04']))->assertOk()->assertSee('PB-800001 · Confirmed · Walk-in', false)->assertSee(route('owner.walk-in.index'), false);
        $this->assertTrue($walkIn->isWalkIn());
    }
}
