<?php

namespace Tests\Feature\Schedule;

use App\Enums\BookingStatus as Status;
use App\Models\AvailabilityRule;
use App\Models\BlockedDate;
use App\Models\BookingSetting;
use App\Models\PhotographyBooking;
use App\Models\PhotographyPackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Booking\SetsUpSchedule;
use Tests\TestCase;

/** Phase 4D: the owner's opening hours, booking settings and blocked dates, with the safety net. */
class ScheduleEditorTest extends TestCase
{
    use RefreshDatabase, SetsUpSchedule;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSchedule(buffer: 15);
        $this->owner = User::factory()->owner()->create();
    }

    /** Monday to Saturday 9 to 5, Sunday closed: the test week as a form. */
    private function hours(array $overrides = []): array
    {
        $hours = [];
        foreach (range(0, 6) as $weekday) {
            $hours[$weekday] = $weekday === 0 ? ['open' => '0', 'opens' => '', 'closes' => ''] : ['open' => '1', 'opens' => '09:00', 'closes' => '17:00'];
        }

        return array_replace($hours, $overrides);
    }

    private function settingsForm(array $overrides = []): array
    {
        return array_replace(['buffer_minutes' => 15, 'min_notice_hours' => 24, 'max_advance_days' => 30, 'concurrent_sessions' => 1], $overrides);
    }

    private function send(string $action, array $data, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)->from(route('owner.schedule.edit'))->post(route("owner.schedule.{$action}"), $data);
    }

    private function booking(Status $status, string $manila, int $minutes = 60): PhotographyBooking
    {
        return PhotographyBooking::factory()->status($status)->at($this->manila($manila), $minutes)->create();
    }

    private function freeTimes(string $day): array
    {
        $package = PhotographyPackage::factory()->published()->create(['duration_minutes' => 60]);

        return collect($this->actingAs(User::factory()->create())->getJson(route('customer.bookings.slots', ['package' => $package->id, 'from' => $day, 'to' => $day]))->json('days.'.$day))
            ->pluck('time')->all();
    }

    // Access

    public function test_only_the_owner_reaches_the_editor_and_its_actions(): void
    {
        $customer = User::factory()->create();
        $blocked = BlockedDate::factory()->create(['date' => '2026-11-10']);

        $this->get(route('owner.schedule.edit'))->assertRedirect(route('login'));
        $this->get(route('owner.schedule.index'))->assertRedirect(route('login'));
        $this->actingAs($customer)->get(route('owner.schedule.edit'))->assertForbidden();
        $this->actingAs($customer)->get(route('owner.schedule.index'))->assertForbidden();
        $this->send('hours', ['hours' => $this->hours([1 => ['open' => '0']])], $customer)->assertForbidden();
        $this->send('settings', $this->settingsForm(['buffer_minutes' => 0]), $customer)->assertForbidden();
        $this->send('block', ['from' => '2026-11-11'], $customer)->assertForbidden();
        $this->actingAs($customer)->delete(route('owner.schedule.unblock', $blocked))->assertForbidden();

        $this->assertTrue(AvailabilityRule::query()->where('weekday', 1)->value('is_open'));
        $this->assertSame(15, BookingSetting::current()->buffer_minutes);
        $this->assertSame(1, BlockedDate::count());
        $this->actingAs($this->owner)->get(route('owner.schedule.edit'))->assertOk()->assertSee('Weekly opening hours');
    }

    // Opening hours

    public function test_hours_are_saved_and_the_booking_calendar_uses_them_at_once(): void
    {
        $this->assertContains('9:00 AM', $this->freeTimes('2026-11-06'));

        $this->send('hours', ['hours' => $this->hours([5 => ['open' => '1', 'opens' => '13:00', 'closes' => '16:30']])])
            ->assertSessionHas('status');

        $friday = AvailabilityRule::query()->where('weekday', 5)->first();
        $this->assertSame(['13:00:00', '16:30:00'], [$friday->opens_at, $friday->closes_at]);
        $times = $this->freeTimes('2026-11-06');
        $this->assertNotContains('9:00 AM', $times);
        $this->assertSame('1:00 PM', $times[0]);
        $this->assertSame('3:30 PM', end($times));
    }

    public function test_a_closed_day_keeps_no_times_and_missing_rows_are_created(): void
    {
        AvailabilityRule::query()->where('weekday', 3)->delete();

        $this->send('hours', ['hours' => $this->hours([3 => ['open' => '0', 'opens' => '10:00', 'closes' => '12:00']])])->assertSessionHas('status');

        $wednesday = AvailabilityRule::query()->where('weekday', 3)->sole();
        $this->assertFalse($wednesday->is_open);
        $this->assertNull($wednesday->opens_at);
        $this->assertSame(7, AvailabilityRule::count());
        $this->assertSame([], $this->freeTimes('2026-11-04'));
    }

    /** @return array<string, array{array<int, mixed>}> */
    public static function badHours(): array
    {
        return [
            'closes before opens' => [[2 => ['open' => '1', 'opens' => '17:00', 'closes' => '09:00']]],
            'closes equal to opens' => [[2 => ['open' => '1', 'opens' => '09:00', 'closes' => '09:00']]],
            'off the time step' => [[2 => ['open' => '1', 'opens' => '09:10', 'closes' => '17:00']]],
            'not a time' => [[2 => ['open' => '1', 'opens' => '25:00', 'closes' => '17:00']]],
            'open without times' => [[2 => ['open' => '1', 'opens' => '', 'closes' => '']]],
            'a time as an array' => [[2 => ['open' => '1', 'opens' => ['09:00'], 'closes' => '17:00']]],
            'a weekday that does not exist' => [[9 => ['open' => '1', 'opens' => '09:00', 'closes' => '17:00']]],
        ];
    }

    #[DataProvider('badHours')]
    public function test_bad_hours_are_refused_and_nothing_changes(array $overrides): void
    {
        $this->send('hours', ['hours' => $this->hours($overrides)])->assertSessionHasErrors();

        $this->assertSame('09:00:00', AvailabilityRule::query()->where('weekday', 2)->value('opens_at'));
    }

    public function test_hours_without_all_seven_days_are_refused(): void
    {
        $hours = $this->hours();
        unset($hours[4]);

        $this->send('hours', ['hours' => $hours])->assertSessionHasErrors('hours');
        $this->send('hours', ['hours' => 'all day'])->assertSessionHasErrors('hours');
    }

    // Booking settings

    public function test_settings_are_saved_and_change_the_booking_calendar(): void
    {
        $this->assertContains('9:00 AM', $this->freeTimes('2026-11-03'));

        // 48 hours of notice: Tuesday 3 November is now too soon
        $this->send('settings', $this->settingsForm(['min_notice_hours' => 48, 'buffer_minutes' => 30]))->assertSessionHas('status');

        $this->assertSame([30, 48, 30, 1], [BookingSetting::current()->buffer_minutes, BookingSetting::current()->min_notice_hours, BookingSetting::current()->max_advance_days, BookingSetting::current()->concurrent_sessions]);
        $this->assertSame([], $this->freeTimes('2026-11-03'));
    }

    public function test_saving_creates_the_settings_row_when_missing_and_never_touches_the_lock(): void
    {
        DB::table('booking_settings')->delete();

        $this->send('settings', $this->settingsForm(['buffer_minutes' => 45]))->assertSessionHas('status');
        $this->assertSame(1, DB::table('booking_settings')->count());
        $this->assertSame(45, (int) DB::table('booking_settings')->value('buffer_minutes'));

        DB::table('booking_settings')->update(['lock_version' => 7]);
        $this->send('settings', $this->settingsForm(['buffer_minutes' => 50, 'lock_version' => 0]))->assertSessionHas('status');
        $this->send('settings', $this->settingsForm(['buffer_minutes' => 55]))->assertSessionHas('status');

        $this->assertSame(1, DB::table('booking_settings')->count());
        $this->assertSame(7, (int) DB::table('booking_settings')->value('lock_version'));
        $this->assertSame(55, (int) DB::table('booking_settings')->value('buffer_minutes'));
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function badSettings(): array
    {
        return [
            'negative buffer' => [['buffer_minutes' => -1], 'buffer_minutes'],
            'buffer too long' => [['buffer_minutes' => 241], 'buffer_minutes'],
            'notice too long' => [['min_notice_hours' => 721], 'min_notice_hours'],
            'zero days ahead' => [['max_advance_days' => 0], 'max_advance_days'],
            'too many days ahead' => [['max_advance_days' => 366], 'max_advance_days'],
            'no sessions' => [['concurrent_sessions' => 0], 'concurrent_sessions'],
            'too many sessions' => [['concurrent_sessions' => 6], 'concurrent_sessions'],
            'not a whole number' => [['buffer_minutes' => '10.5'], 'buffer_minutes'],
            'text' => [['min_notice_hours' => 'a day'], 'min_notice_hours'],
            'an array' => [['concurrent_sessions' => [2]], 'concurrent_sessions'],
            'missing' => [['max_advance_days' => null], 'max_advance_days'],
        ];
    }

    #[DataProvider('badSettings')]
    public function test_bad_settings_are_refused(array $overrides, string $field): void
    {
        $this->send('settings', $this->settingsForm($overrides))->assertSessionHasErrors($field);

        $this->assertSame(15, BookingSetting::current()->buffer_minutes);
    }

    // Blocked dates

    public function test_one_day_or_a_range_is_blocked_one_row_per_day(): void
    {
        $this->send('block', ['from' => '2026-11-10', 'reason' => '  Holiday  '])->assertSessionHas('status', '1 day blocked.');
        $this->send('block', ['from' => '2026-11-09', 'to' => '2026-11-12'])->assertSessionHas('status', '3 days blocked. 1 was already blocked.');

        $this->assertSame(['2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12'], BlockedDate::query()->orderBy('date')->get()->map(fn ($day) => $day->date->format('Y-m-d'))->all());
        $this->assertSame('Holiday', BlockedDate::query()->whereBetween('date', ['2026-11-10', '2026-11-10'])->value('reason'));
        $this->assertSame($this->owner->id, BlockedDate::query()->first()->created_by);
        $this->assertSame([], $this->freeTimes('2026-11-11'));
    }

    public function test_past_too_long_and_bad_ranges_are_refused(): void
    {
        $this->send('block', ['from' => '2026-11-01'])->assertSessionHasErrors('from');
        $this->send('block', ['from' => '2026-11-03', 'to' => '2027-01-04'])->assertSessionHasErrors('to');
        $this->send('block', ['from' => '2026-11-10', 'to' => '2026-11-09'])->assertSessionHasErrors('to');
        $this->send('block', ['from' => 'tomorrow'])->assertSessionHasErrors('from');
        $this->send('block', ['from' => '2026-11-10', 'reason' => str_repeat('a', 121)])->assertSessionHasErrors('reason');

        $this->assertSame(0, BlockedDate::count());

        // Today itself may be blocked; exactly 62 days are allowed
        $this->send('block', ['from' => '2026-11-02', 'to' => '2027-01-02'])->assertSessionHas('status', '62 days blocked.');
    }

    public function test_a_blocked_day_can_be_unblocked(): void
    {
        $this->send('block', ['from' => '2026-11-10']);
        $blocked = BlockedDate::sole();

        $this->actingAs($this->owner)->from(route('owner.schedule.edit'))->delete(route('owner.schedule.unblock', $blocked))->assertSessionHas('status');

        $this->assertSame(0, BlockedDate::count());
        $this->assertNotSame([], $this->freeTimes('2026-11-10'));
    }

    // The safety net

    public function test_closing_a_weekday_with_a_confirmed_session_needs_the_tick_and_never_changes_the_booking(): void
    {
        $confirmed = $this->booking(Status::Confirmed, '2026-11-07 10:00');
        $request = $this->booking(Status::Requested, '2026-11-14 11:00');
        $saturdayClosed = ['hours' => $this->hours([6 => ['open' => '0', 'opens' => '', 'closes' => '']])];

        $this->send('hours', $saturdayClosed)->assertSessionHas('error')->assertSessionHas('impact', fn ($impact) => $impact['needsTick']
            && array_column($impact['confirmed'], 'reference') === [$confirmed->reference_number]
            && array_column($impact['pending'], 'reference') === [$request->reference_number]);
        $this->assertTrue(AvailabilityRule::query()->where('weekday', 6)->value('is_open'));

        $this->send('hours', $saturdayClosed + ['acknowledge' => '1'])->assertSessionHas('status');
        $this->assertFalse(AvailabilityRule::query()->where('weekday', 6)->value('is_open'));
        $this->assertSame(Status::Confirmed, $confirmed->fresh()->status);
        $this->assertSame(Status::Requested, $request->fresh()->status);
    }

    public function test_shorter_hours_list_the_session_that_would_run_past_closing(): void
    {
        $late = $this->booking(Status::Confirmed, '2026-11-05 15:30');
        $this->booking(Status::Confirmed, '2026-11-05 10:00');

        $this->send('hours', ['hours' => $this->hours([4 => ['open' => '1', 'opens' => '09:00', 'closes' => '16:00']])])
            ->assertSessionHas('impact', fn ($impact) => array_column($impact['confirmed'], 'reference') === [$late->reference_number]);
    }

    public function test_pending_requests_alone_never_block_saving(): void
    {
        $request = $this->booking(Status::Requested, '2026-11-07 10:00');

        $this->send('hours', ['hours' => $this->hours([6 => ['open' => '0']])])
            ->assertSessionHas('status')
            ->assertSessionHas('impact', fn ($impact) => ! $impact['needsTick'] && array_column($impact['pending'], 'reference') === [$request->reference_number]);
        $this->assertFalse(AvailabilityRule::query()->where('weekday', 6)->value('is_open'));
    }

    public function test_past_and_cancelled_bookings_are_not_counted(): void
    {
        PhotographyBooking::factory()->status(Status::Cancelled)->at($this->manila('2026-11-07 10:00'))->create();
        $this->travelTo($this->manila('2026-11-07 12:00'));
        $this->booking(Status::Confirmed, '2026-11-07 10:00');

        $this->send('hours', ['hours' => $this->hours([6 => ['open' => '0']])])->assertSessionHas('status')->assertSessionMissing('impact');
    }

    public function test_blocking_a_day_with_a_confirmed_session_needs_the_tick(): void
    {
        $confirmed = $this->booking(Status::Confirmed, '2026-11-10 10:00');

        $this->send('block', ['from' => '2026-11-09', 'to' => '2026-11-11'])->assertSessionHas('error')
            ->assertSessionHas('impact', fn ($impact) => array_column($impact['confirmed'], 'reference') === [$confirmed->reference_number]);
        $this->assertSame(0, BlockedDate::count());

        $this->send('block', ['from' => '2026-11-09', 'to' => '2026-11-11', 'acknowledge' => '1'])->assertSessionHas('status');
        $this->assertSame(3, BlockedDate::count());
        $this->assertSame(Status::Confirmed, $confirmed->fresh()->status);
    }

    public function test_fewer_sessions_at_once_or_a_longer_buffer_lists_the_overlapping_sessions(): void
    {
        $this->settings(buffer: 0, sessions: 2);
        $first = $this->booking(Status::Confirmed, '2026-11-05 10:00');
        $second = $this->booking(Status::Confirmed, '2026-11-05 10:30');
        $alone = $this->booking(Status::Confirmed, '2026-11-05 14:00');

        $this->send('settings', $this->settingsForm(['buffer_minutes' => 0, 'concurrent_sessions' => 1]))->assertSessionHas('error')
            ->assertSessionHas('impact', fn ($impact) => array_column($impact['confirmed'], 'reference') === [$first->reference_number, $second->reference_number]);
        $this->assertSame(2, BookingSetting::current()->concurrent_sessions);

        // Back to back sessions are fine with no buffer, but not with a 30-minute one
        $next = $this->booking(Status::Confirmed, '2026-11-05 15:00');
        $this->send('settings', $this->settingsForm(['buffer_minutes' => 30, 'concurrent_sessions' => 2]))->assertSessionHas('status');
        $this->send('settings', $this->settingsForm(['buffer_minutes' => 30, 'concurrent_sessions' => 1]))
            ->assertSessionHas('impact', fn ($impact) => in_array($alone->reference_number, array_column($impact['confirmed'], 'reference'), true)
                && in_array($next->reference_number, array_column($impact['confirmed'], 'reference'), true));

        $this->send('settings', $this->settingsForm(['buffer_minutes' => 30, 'concurrent_sessions' => 1, 'acknowledge' => '1']))->assertSessionHas('status');
        $this->assertSame(1, BookingSetting::current()->concurrent_sessions);
        $this->assertSame(4, PhotographyBooking::query()->where('status', Status::Confirmed)->count());
    }

    public function test_the_editor_shows_the_safety_net_list_with_links_and_the_tick(): void
    {
        $confirmed = $this->booking(Status::Confirmed, '2026-11-07 10:00');

        $this->send('hours', ['hours' => $this->hours([6 => ['open' => '0']])]);
        $this->actingAs($this->owner)->get(route('owner.schedule.edit'))
            ->assertSee('These confirmed sessions would fall outside the new schedule')
            ->assertSee(route('owner.bookings.show', $confirmed), false)
            ->assertSee('Save anyway');
    }

    public function test_hostile_text_in_a_reason_is_escaped(): void
    {
        $this->send('block', ['from' => '2026-11-10', 'reason' => '<script>alert(1)</script>']);

        $this->actingAs($this->owner)->get(route('owner.schedule.edit'))->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
        $this->actingAs($this->owner)->get(route('owner.schedule.index', ['date' => '2026-11-10']))->assertDontSee('<script>alert(1)</script>', false);
    }
}
