<?php

namespace Tests\Feature\Schedule;

use App\Enums\BookingStatus as Status;
use App\Models\AvailabilityRule;
use App\Models\BlockedDate;
use App\Models\PhotographyBooking;
use App\Models\User;
use App\Services\ScheduleCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Booking\SetsUpSchedule;
use Tests\TestCase;

/** Phase 4D: the owner's calendar (month, week, day) from real bookings, blocked dates and opening hours. */
class ScheduleCalendarTest extends TestCase
{
    use RefreshDatabase, SetsUpSchedule;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSchedule();
        $this->owner = User::factory()->owner()->create();
    }

    private function booking(Status $status, string $manila, array $attributes = []): PhotographyBooking
    {
        return PhotographyBooking::factory()->status($status)->at($this->manila($manila))->create($attributes);
    }

    private function calendar(array $query = [])
    {
        return $this->actingAs($this->owner)->get(route('owner.schedule.index', $query));
    }

    private function day(string $date): array
    {
        $calendar = app(ScheduleCalendarService::class)->build('month', $date);

        return collect($calendar['days'])->firstWhere('date', $date);
    }

    public function test_the_month_shows_every_kind_of_booking_and_day(): void
    {
        $customer = User::factory()->create(['name' => 'Ana Reyes']);
        $confirmed = $this->booking(Status::Confirmed, '2026-11-05 10:00', ['customer_id' => $customer->id, 'snapshot' => ['package' => ['name' => 'Family Portrait Session', 'duration_minutes' => 60], 'theme' => null]]);
        $requested = $this->booking(Status::Requested, '2026-11-05 13:00');
        $proposed = $this->booking(Status::Proposed, '2026-11-06 10:00');
        $completed = $this->booking(Status::Completed, '2026-11-02 09:00');
        $cancelled = $this->booking(Status::Cancelled, '2026-11-07 10:00');
        $rejected = $this->booking(Status::Rejected, '2026-11-07 11:00');
        BlockedDate::factory()->create(['date' => '2026-11-11', 'reason' => 'Town fiesta']);

        $html = $this->calendar()->assertOk()
            ->assertSee('November 2026')
            ->assertSee('Ana Reyes')
            ->assertSee('Family Portrait Session')
            ->assertSee('10:00 AM')
            ->assertSee('Blocked: Town fiesta')
            ->assertSee('Closed')
            ->assertSee('Today')
            ->getContent();

        foreach ([$confirmed, $requested, $proposed, $completed] as $shown) {
            $this->assertStringContainsString(route('owner.bookings.show', $shown), $html);
        }
        foreach ([$cancelled, $rejected] as $hidden) {
            $this->assertStringNotContainsString(route('owner.bookings.show', $hidden), $html);
        }

        // Requests and proposals are outlined, confirmed sessions solid
        $this->assertMatchesRegularExpression('/href="'.preg_quote(route('owner.bookings.show', $requested), '/').'"\s+class="[^"]*border-dashed/', $html);
        $this->assertMatchesRegularExpression('/href="'.preg_quote(route('owner.bookings.show', $confirmed), '/').'"\s+class="[^"]*bg-ink/', $html);
    }

    public function test_a_crowded_day_shows_three_and_a_count(): void
    {
        foreach (['09:00', '10:00', '11:00', '13:00', '14:00'] as $time) {
            $this->booking(Status::Requested, "2026-11-05 {$time}");
        }

        $this->calendar()->assertSee('+2 more')->assertSee(e(route('owner.schedule.index', ['view' => 'day', 'date' => '2026-11-05'])), false);
        $this->calendar(['view' => 'day', 'date' => '2026-11-05'])->assertSee('2:00 PM')->assertDontSee('+2 more');
    }

    public function test_week_and_day_views_and_their_navigation(): void
    {
        $this->booking(Status::Confirmed, '2026-11-05 10:00');

        $this->calendar(['view' => 'week', 'date' => '2026-11-05'])->assertOk()
            ->assertSee('Week of Nov 2, 2026')
            ->assertSee(e(route('owner.schedule.index', ['view' => 'week', 'date' => '2026-10-29'])), false)
            ->assertSee(e(route('owner.schedule.index', ['view' => 'week', 'date' => '2026-11-12'])), false);

        $this->calendar(['view' => 'day', 'date' => '2026-11-05'])->assertOk()
            ->assertSee('Thursday, November 5, 2026')
            ->assertSee(e(route('owner.schedule.index', ['view' => 'day', 'date' => '2026-11-04'])), false)
            ->assertSee(e(route('owner.schedule.index', ['view' => 'day', 'date' => '2026-11-06'])), false);

        $this->calendar(['view' => 'month', 'date' => '2026-11-20'])
            ->assertSee(e(route('owner.schedule.index', ['view' => 'month', 'date' => '2026-10-01'])), false)
            ->assertSee(e(route('owner.schedule.index', ['view' => 'month', 'date' => '2026-12-01'])), false)
            ->assertSee(e(route('owner.schedule.index', ['view' => 'month', 'date' => '2026-11-02'])), false);
    }

    public function test_an_empty_period_says_so(): void
    {
        AvailabilityRule::query()->update(['is_open' => true, 'opens_at' => '09:00:00', 'closes_at' => '17:00:00']);

        $this->calendar(['view' => 'week', 'date' => '2027-03-10'])->assertSee('Nothing in this week');
    }

    public function test_hostile_parameters_go_back_to_this_month(): void
    {
        foreach ([['view' => 'year'], ['view' => ['month']], ['date' => '2026-02-30'], ['date' => 'next week'], ['date' => '1900-01-01'], ['date' => ['x']]] as $query) {
            $this->calendar($query)->assertRedirect(route('owner.schedule.index'))->assertSessionHasErrors();
        }
    }

    public function test_a_booking_lands_on_its_manila_day_across_midnight_utc(): void
    {
        // 16:30 UTC on 4 November is 00:30 on 5 November in Manila
        $booking = PhotographyBooking::factory()->status(Status::Confirmed)->at(Carbon::parse('2026-11-04 16:30:00', 'UTC'))->create();
        // 15:59 UTC on 4 November is still 4 November in Manila (23:59)
        $late = PhotographyBooking::factory()->status(Status::Confirmed)->at(Carbon::parse('2026-11-04 15:59:00', 'UTC'))->create();

        $this->assertSame([$booking->id], $this->day('2026-11-05')['bookings']->pluck('id')->all());
        $this->assertSame([$late->id], $this->day('2026-11-04')['bookings']->pluck('id')->all());

        $this->calendar(['view' => 'day', 'date' => '2026-11-05'])->assertSee(route('owner.bookings.show', $booking), false)->assertSee('12:30 AM');
        $this->calendar(['view' => 'day', 'date' => '2026-11-04'])->assertDontSee(route('owner.bookings.show', $booking), false);
    }

    public function test_the_month_is_read_with_the_same_few_queries_however_many_bookings(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->calendar(['date' => '2026-11-15'])->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->booking(Status::Confirmed, '2026-11-05 10:00');
        $one = $count();

        foreach (range(1, 25) as $i) {
            PhotographyBooking::factory()->status($i % 2 ? Status::Confirmed : Status::Requested)
                ->at($this->manila('2026-11-'.str_pad((string) (3 + $i % 20), 2, '0', STR_PAD_LEFT).' 10:00'))
                ->create(['customer_id' => User::factory()->create()->id]);
        }
        BlockedDate::factory()->count(3)->sequence(['date' => '2026-11-24'], ['date' => '2026-11-25'], ['date' => '2026-11-26'])->create();

        $this->assertSame($one, $count(), 'The calendar must not query once per booking or per day');
        $this->assertLessThanOrEqual(12, $one);
    }
}
