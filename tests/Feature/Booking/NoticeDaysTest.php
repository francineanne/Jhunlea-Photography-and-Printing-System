<?php

namespace Tests\Feature\Booking;

use App\Enums\BookingStatus;
use App\Exceptions\BookingTransitionException;
use App\Models\BookingSetting;
use App\Models\PhotographyBooking;
use App\Models\PhotographyPackage;
use App\Models\User;
use App\Services\AvailabilityService;
use App\Services\BookingService;
use App\Services\BookingTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\SetsPaymentRules;
use Tests\TestCase;

/**
 * Phase 10I: the minimum notice is whole Manila calendar days (1 to 60), and same-day photoshoots are never allowed:
 * online, in the studio's proposals and at the counter. The week of SetsUpSchedule: Monday to Saturday 9:00 to 17:00,
 * Sunday closed. Thursday is 5 November 2026.
 */
class NoticeDaysTest extends TestCase
{
    use RefreshDatabase, SetsPaymentRules, SetsUpSchedule;

    private const SAME_DAY = 'Same-day photoshoots are not allowed. Choose tomorrow or later.';

    private User $owner;

    private PhotographyPackage $package;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSchedule();
        $this->withoutDownpaymentsOrPrepayment();
        $this->owner = User::factory()->owner()->create();
        $this->package = PhotographyPackage::factory()->published()->create(['duration_minutes' => 60, 'max_people' => 4, 'price' => '1500.00', 'price_requires_confirmation' => false]);
    }

    private function at(string $manila, int $noticeDays): void
    {
        $this->travelTo($this->manila($manila));
        $this->settings(noticeDays: $noticeDays);
    }

    /** The first day (Manila) with a free time, looking 10 days ahead. */
    private function firstOpenDay(): string
    {
        $today = now('Asia/Manila')->format('Y-m-d');
        $last = now('Asia/Manila')->addDays(10)->format('Y-m-d');

        return app(AvailabilityService::class)->slots(60, $today, $last)->first()->startsAt->setTimezone('Asia/Manila')->format('D Y-m-d H:i');
    }

    public function test_one_day_on_thursday_evening_opens_friday_from_the_first_photoshoot_hour(): void
    {
        $this->at('2026-11-05 19:30', 1);

        $this->assertSame('Fri 2026-11-06 09:00', $this->firstOpenDay());
        $this->assertSame(self::SAME_DAY, BookingSetting::current()->noticeReason());
    }

    public function test_two_days_on_thursday_morning_and_evening_both_open_saturday(): void
    {
        foreach (['2026-11-05 08:00', '2026-11-05 19:30'] as $now) {
            $this->at($now, 2);

            $this->assertSame('Sat 2026-11-07 09:00', $this->firstOpenDay(), $now);
            $this->assertSame('Book at least 2 days ahead.', app(AvailabilityService::class)->noticeRefusal($this->manila('2026-11-06 10:00')));
        }
    }

    public function test_three_days_on_thursday_with_sunday_closed_opens_monday(): void
    {
        $this->at('2026-11-05 10:00', 3);

        $this->assertSame('Mon 2026-11-09 09:00', $this->firstOpenDay());
    }

    public function test_today_is_never_offered_and_place_refuses_it_for_any_notice(): void
    {
        $bookings = app(BookingService::class);

        foreach ([1, 2, 3, 60] as $days) {
            $this->at('2026-11-05 08:00', $days);
            $today = $this->manila('2026-11-05 15:00');

            $this->assertSame([], app(AvailabilityService::class)->slots(60, '2026-11-05', '2026-11-05')->all(), "{$days} days");
            $this->assertSame(BookingSetting::current()->noticeReason(), $bookings->slotRefusal($this->package, $today), "{$days} days");
        }
    }

    public function test_the_wizard_says_why_the_days_before_the_first_one_are_closed(): void
    {
        $this->at('2026-11-05 10:00', 2);
        $customer = User::factory()->create();

        $json = $this->actingAs($customer)->getJson(route('customer.bookings.slots', ['package' => $this->package->id, 'from' => '2026-11-05', 'to' => '2026-11-10']))->assertOk();
        $this->assertSame('2026-11-07', $json->json('bookable_from'));
        $this->assertSame('too_soon', $json->json('info.2026-11-05.state'));
        $this->assertSame('Book at least 2 days ahead.', $json->json('info.2026-11-06.text'));

        $this->actingAs($customer)->get(route('customer.bookings.create'))->assertOk()
            ->assertSee('Photoshoots must be booked at least 2 days ahead; same-day photoshoots are not allowed.')
            ->assertSee('Book at least 2 days ahead.');
    }

    public function test_the_editor_accepts_1_and_60_and_refuses_0_and_61(): void
    {
        $form = fn (int $days) => ['buffer_minutes' => 15, 'min_notice_days' => $days, 'max_advance_days' => 90, 'request_hold_hours' => 48, 'max_sessions_per_day' => ''];
        $send = fn (int $days) => $this->actingAs($this->owner)->from(route('owner.schedule.edit'))->post(route('owner.schedule.settings'), $form($days));

        foreach ([1, 60] as $days) {
            $send($days)->assertSessionHasNoErrors();
            $this->assertSame($days, BookingSetting::current()->min_notice_days);
        }

        foreach ([0, 61] as $days) {
            $send($days)->assertSessionHasErrors(['min_notice_days' => 'The minimum notice must be a whole number from 1 to 60.']);
            $this->assertSame(60, BookingSetting::current()->min_notice_days);
        }

        $this->actingAs($this->owner)->get(route('owner.schedule.edit'))->assertOk()
            ->assertSee('How many days ahead a customer must book. 1 means from tomorrow; same-day photoshoots are never allowed. 1 to 60.');
    }

    public function test_the_migration_converts_hours_to_whole_days(): void
    {
        $migration = require database_path('migrations/2026_11_30_100000_change_min_notice_to_days.php');

        foreach ([24 => 1, 48 => 2, 0 => 1, 30 => 2, 1 => 1, 72 => 3] as $hours => $days) {
            $migration->down();
            DB::table('booking_settings')->where('id', BookingSetting::ROW_ID)->update(['min_notice_hours' => $hours]);
            $migration->up();

            $this->assertSame($days, (int) DB::table('booking_settings')->where('id', BookingSetting::ROW_ID)->value('min_notice_days'), "{$hours} hours");
        }

        // down() gives the hours back as days x 24
        DB::table('booking_settings')->where('id', BookingSetting::ROW_ID)->update(['min_notice_days' => 3]);
        $migration->down();
        $this->assertSame(72, (int) DB::table('booking_settings')->where('id', BookingSetting::ROW_ID)->value('min_notice_hours'));
        $migration->up();
    }

    public function test_the_studio_proposal_follows_the_notice(): void
    {
        $this->at('2026-11-05 10:00', 2);
        $booking = PhotographyBooking::factory()->status(BookingStatus::Requested)->at($this->manila('2026-11-10 10:00'))->create(['photography_package_id' => $this->package->id]);
        $service = app(BookingTransitionService::class);

        try {
            $service->propose($booking, $this->owner, $this->manila('2026-11-06 10:00'), 'Would Friday work?');
            $this->fail('A proposal for Friday must be refused with 2 days of notice on Thursday.');
        } catch (BookingTransitionException $e) {
            $this->assertSame('Book at least 2 days ahead.', $e->getMessage());
        }

        $service->propose($booking, $this->owner, $this->manila('2026-11-07 10:00'), 'Would Saturday work?');
        $this->assertSame(BookingStatus::Proposed, $booking->fresh()->status);
    }

    public function test_the_counter_follows_the_notice_even_with_confirm_anyway_or_over_the_daily_limit(): void
    {
        $this->at('2026-11-05 10:00', 3);
        $payload = fn (string $date, array $ticks = []) => [
            'counter_token' => (string) Str::uuid(),
            'new_customer_name' => 'Lola Walk-in',
            'package' => $this->package->id,
            'custom_theme_text' => 'Plain white wall',
            'date' => $date,
            'time' => '14:00',
            'group_size' => 2,
            'new_customer_phone' => '0917 555 0101',
            'confirmed_price' => '1500',
        ] + $ticks;
        $send = fn (array $data) => $this->actingAs($this->owner)->post(route('owner.bookings.walk-in.store'), $data);

        foreach (['2026-11-05', '2026-11-06', '2026-11-07'] as $date) {
            foreach ([[], ['outside_schedule' => '1'], ['over_daily_limit' => '1'], ['outside_schedule' => '1', 'over_daily_limit' => '1']] as $ticks) {
                $send($payload($date, $ticks))->assertSessionHasErrors(['starts_at' => 'Book at least 3 days ahead.']);
            }
        }
        $this->assertSame(0, PhotographyBooking::query()->count());

        $send($payload('2026-11-09'))->assertSessionHasNoErrors();
        $this->assertTrue(PhotographyBooking::query()->sole()->starts_at->eq($this->manila('2026-11-09 14:00')));
    }

    public function test_the_manila_day_boundary_late_thursday_allows_friday(): void
    {
        // 23:30 Thursday in Manila is still Thursday 15:30 UTC: with 1 day of notice Friday is bookable
        $this->at('2026-11-05 23:30', 1);

        $this->assertNull(app(AvailabilityService::class)->noticeRefusal($this->manila('2026-11-06 09:00')));
        $this->assertSame(self::SAME_DAY, app(AvailabilityService::class)->noticeRefusal($this->manila('2026-11-05 23:45')));
        $this->assertSame('Fri 2026-11-06 09:00', $this->firstOpenDay());
    }

    public function test_the_sentence_customers_read(): void
    {
        $this->settings(noticeDays: 1);
        $this->assertSame('Photoshoots must be booked at least 1 day ahead; same-day photoshoots are not allowed.', BookingSetting::current()->noticeSentence());
        $this->get(route('terms'))->assertOk()->assertSee('Photoshoots must be booked at least 1 day ahead; same-day photoshoots are not allowed.');

        $this->settings(noticeDays: 3);
        $this->assertSame('Photoshoots must be booked at least 3 days ahead; same-day photoshoots are not allowed.', BookingSetting::current()->noticeSentence());
    }
}
