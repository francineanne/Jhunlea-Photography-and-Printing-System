<?php

namespace Tests\Feature\Booking;

use App\Enums\BookingStatus;
use App\Exceptions\BookingTransitionException;
use App\Models\AvailabilityRule;
use App\Models\BookingSetting;
use App\Models\PhotographyBooking;
use App\Models\PhotographyPackage;
use App\Models\User;
use App\Services\Assistant\CodeAnswers;
use App\Services\Assistant\KnowledgeBuilder;
use App\Services\Assistant\LiveFacts;
use App\Services\AvailabilityService;
use App\Services\BookingService;
use App\Services\BookingTransitionService;
use App\Services\ScheduleConflictService;
use App\Services\ShopSettingsService;
use App\Support\Slot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\SetsPaymentRules;
use Tests\TestCase;

/**
 * Phase 10A: scheduling. A request and a proposed time HOLD their time for the owner's "request hold" hours, the
 * holding rule lives in one place (PhotographyBooking::scopeHoldingTime / holdsTime), a request whose time passed is
 * Expired (derived), one studio, the daily limit, the people range of a package, and photoshoot hours apart from the
 * shop's opening hours. "Now" is Monday 2 November 2026, 08:00 in Manila; photoshoot hours Monday to Saturday 9 to 5.
 */
class SchedulingHoldsTest extends TestCase
{
    use RefreshDatabase, SetsPaymentRules, SetsUpSchedule;

    private User $owner;

    private User $ana;

    private User $ben;

    /** @var array<int, PhotographyPackage> packages by length in minutes */
    private array $packages = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Written before Phase 10B: no downpayment, printing paid at pickup (the new rules have their own tests)
        $this->withoutDownpaymentsOrPrepayment();

        $this->setUpSchedule();
        $this->owner = User::factory()->owner()->create();
        $this->ana = User::factory()->create(['name' => 'Ana Cruz']);
        $this->ben = User::factory()->create(['name' => 'Ben Reyes']);

        foreach ([60, 90, 120] as $minutes) {
            $this->packages[$minutes] = PhotographyPackage::factory()->published()->create([
                'name' => "Session {$minutes}", 'duration_minutes' => $minutes, 'max_people' => 6, 'price' => '1500.00', 'price_requires_confirmation' => false,
            ]);
        }
    }

    private function place(User $customer, string $manila, int $minutes = 60, int $people = 2, ?PhotographyPackage $package = null): PhotographyBooking
    {
        return app(BookingService::class)->place($customer, $package ?? $this->packages[$minutes], null, 'Plain white wall', $this->manila($manila), $people, '0917 123 4567', null, false);
    }

    private function refusal(callable $action): string
    {
        try {
            $action();
        } catch (ValidationException $e) {
            return (string) collect($e->errors())->flatten()->first();
        } catch (BookingTransitionException $e) {
            return $e->getMessage();
        }

        $this->fail('The action was not refused.');
    }

    /** @return list<string> */
    private function times(int $minutes, string $date, bool $atCounter = false): array
    {
        return app(AvailabilityService::class)->slots($minutes, $date, $date, atCounter: $atCounter)
            ->map(fn (Slot $slot) => $slot->startsAt->copy()->setTimezone('Asia/Manila')->format('H:i'))->all();
    }

    private function confirmedAt(string $manila, int $minutes = 60): PhotographyBooking
    {
        return PhotographyBooking::factory()->status(BookingStatus::Confirmed)->at($this->manila($manila), $minutes)->create(['photography_package_id' => $this->packages[60]->id]);
    }

    private function transitions(): BookingTransitionService
    {
        return app(BookingTransitionService::class);
    }

    private function setLimit(?int $limit): void
    {
        DB::table('booking_settings')->where('id', 1)->update(['max_sessions_per_day' => $limit]);
    }

    /** What each package length may start at around an 11:00 to 12:00 session with a 15-minute buffer, until 5 PM. */
    private function assertTimesAroundElevenWithBuffer(): void
    {
        // Not free: 10:45 to 12:15. 60 minutes: 9:00 and 9:30 before, 12:30 to 16:00 after
        $this->assertSame(['09:00', '09:30', '12:30', '13:00', '13:30', '14:00', '14:30', '15:00', '15:30', '16:00'], $this->times(60, '2026-11-03'));
        // 90 minutes: only 9:00 ends by 10:45; the last start is 15:30 (ends 5:00 PM, closing)
        $this->assertSame(['09:00', '12:30', '13:00', '13:30', '14:00', '14:30', '15:00', '15:30'], $this->times(90, '2026-11-03'));
        // 120 minutes: nothing fits before; the last start is 15:00
        $this->assertSame(['12:30', '13:00', '13:30', '14:00', '14:30', '15:00'], $this->times(120, '2026-11-03'));
    }

    // The single holding rule, around each kind of booking that holds time

    public function test_60_90_and_120_minute_packages_around_a_confirmed_booking_with_the_buffer_and_closing_time(): void
    {
        $this->settings(buffer: 15);
        $this->confirmedAt('2026-11-03 11:00');

        $this->assertTimesAroundElevenWithBuffer();
    }

    public function test_a_new_request_holds_its_time_exactly_like_a_confirmed_booking(): void
    {
        $this->settings(buffer: 15);
        $request = $this->place($this->ana, '2026-11-03 11:00');

        $this->assertSame(BookingStatus::Requested, $request->status);
        $this->assertTrue($request->fresh()->hold_expires_at->eq(Carbon::now('UTC')->addHours(48)));
        $this->assertTrue($request->fresh()->holdsTime());
        $this->assertSame([$request->id], PhotographyBooking::query()->holdingTime()->pluck('id')->all());
        $this->assertTimesAroundElevenWithBuffer();

        // Another customer cannot take the same time, or one that overlaps it or its buffer
        foreach (['2026-11-03 11:00', '2026-11-03 10:30', '2026-11-03 12:00'] as $time) {
            $this->assertSame(BookingService::SLOT_JUST_TAKEN_MESSAGE, $this->refusal(fn () => $this->place($this->ben, $time)));
        }
        $this->assertSame('Someone just requested this time. Please choose another.', BookingService::SLOT_JUST_TAKEN_MESSAGE);
        $this->assertSame(1, PhotographyBooking::count());

        // A time right after the buffer is fine
        $this->assertSame(BookingStatus::Requested, $this->place($this->ben, '2026-11-03 12:30')->status);
    }

    public function test_a_proposed_time_is_held_and_the_time_first_asked_for_is_given_up(): void
    {
        $this->settings(buffer: 15);
        $request = $this->place($this->ana, '2026-11-03 14:00');
        $this->transitions()->propose($request, $this->owner, $this->manila('2026-11-03 11:00'), 'Can you come earlier?');

        $request->refresh();
        $this->assertSame(BookingStatus::Proposed, $request->status);
        $this->assertTrue($request->hold_expires_at->eq(Carbon::now('UTC')->addHours(48)));
        $this->assertTrue($request->heldStart()->eq($this->manila('2026-11-03 11:00')));
        $this->assertTrue($request->heldEnd()->eq($this->manila('2026-11-03 12:00')));

        // The PROPOSED time is what is held now; 2:00 PM is free again
        $this->assertTimesAroundElevenWithBuffer();
        $this->assertSame(BookingService::SLOT_JUST_TAKEN_MESSAGE, $this->refusal(fn () => $this->place($this->ben, '2026-11-03 11:00')));
        $this->assertSame(BookingStatus::Requested, $this->place($this->ben, '2026-11-03 14:00')->status);

        // The customer accepts: confirmed at the proposed time, the hold is cleared (a confirmed booking needs none)
        $this->transitions()->acceptProposal($request, $this->ana);
        $this->assertSame([BookingStatus::Confirmed, null], [$request->fresh()->status, $request->fresh()->hold_expires_at]);
        $this->assertTrue($request->fresh()->starts_at->eq($this->manila('2026-11-03 11:00')));
    }

    public function test_asking_for_another_time_holds_the_first_time_again_only_while_it_is_still_free(): void
    {
        $first = $this->place($this->ana, '2026-11-10 14:00');
        $this->transitions()->propose($first, $this->owner, $this->manila('2026-11-10 10:00'), 'Earlier?');
        $this->transitions()->askForAnotherTime($first, $this->ana, 'Mornings do not work.');
        $this->assertSame(BookingStatus::Requested, $first->fresh()->status);
        $this->assertTrue($first->fresh()->holdsTime());
        $this->assertNotContains('14:00', $this->times(60, '2026-11-10'));

        // Ben takes 2:00 PM while Ana's booking holds a proposed 10:00; Ana then asks again: no second hold on 2:00 PM
        $this->transitions()->propose($first, $this->owner, $this->manila('2026-11-10 10:00'), 'Earlier?');
        $bens = $this->place($this->ben, '2026-11-10 14:00');
        $this->transitions()->askForAnotherTime($first, $this->ana, 'Still no.');

        $this->assertNull($first->fresh()->hold_expires_at);
        $this->assertFalse($first->fresh()->holdsTime());
        $this->assertSame([$bens->id], PhotographyBooking::query()->holdingTime()->pluck('id')->all());
    }

    // A hold that ran out

    public function test_a_hold_that_ran_out_frees_the_time_and_the_request_stays(): void
    {
        $request = $this->place($this->ana, '2026-11-10 11:00');
        $this->assertNotContains('11:00', $this->times(60, '2026-11-10'));

        $this->travel(47)->hours();
        $this->assertNotContains('11:00', $this->times(60, '2026-11-10'));

        $this->travel(2)->hours();
        $this->assertContains('11:00', $this->times(60, '2026-11-10'));
        $this->assertFalse($request->fresh()->holdsTime());
        $this->assertSame(0, PhotographyBooking::query()->holdingTime()->count());

        // Nothing was changed on the request: it still waits for the studio and still counts as an open request
        $this->assertSame(BookingStatus::Requested, $request->fresh()->status);
        $this->assertFalse($request->fresh()->isExpired());
        $this->assertSame(1, PhotographyBooking::query()->waiting()->count());
    }

    public function test_a_request_whose_hold_ran_out_is_confirmed_when_free_and_refused_on_a_clash(): void
    {
        $anas = $this->place($this->ana, '2026-11-10 11:00');
        $free = $this->place($this->ana, '2026-11-11 11:00');
        $this->travel(49)->hours();

        // Nobody else took Wednesday 11:00: the studio confirms it although its hold ran out
        $this->transitions()->confirm($free, $this->owner);
        $this->assertSame(BookingStatus::Confirmed, $free->fresh()->status);

        // Ben requested Tuesday 11:00 after Ana's hold ran out, and holds it now: Ana's request is refused
        $bens = $this->place($this->ben, '2026-11-10 11:00');
        $this->assertSame(BookingTransitionException::conflict()->getMessage(), $this->refusal(fn () => $this->transitions()->confirm($anas, $this->owner)));
        $this->assertSame(BookingStatus::Requested, $anas->fresh()->status);

        // The owner still sees Ana's request as competing with Ben's
        $found = app(ScheduleConflictService::class)->conflictsFor($bens->starts_at, $bens->ends_at, $bens->id);
        $this->assertSame([[], [$anas->id]], [$found['blocking']->pluck('id')->all(), $found['competing']->pluck('id')->all()]);

        $this->transitions()->confirm($bens, $this->owner);
        $this->assertSame(BookingStatus::Confirmed, $bens->fresh()->status);
    }

    // Expired: the time passed without a confirmation

    public function test_a_request_whose_time_passed_is_expired_holds_nothing_and_leaves_the_request_limit(): void
    {
        $requests = collect(['09:00', '11:00', '14:00'])->map(fn (string $time) => $this->place($this->ana, "2026-11-03 {$time}"));
        $this->assertTrue(app(BookingService::class)->atRequestLimit($this->ana));
        $this->assertSame(BookingService::requestLimitMessage(), $this->refusal(fn () => $this->place($this->ana, '2026-11-04 09:00')));

        // Wednesday 7:00 AM: Tuesday's requests were never confirmed, and their 48-hour holds have NOT run out yet
        $this->travelTo($this->manila('2026-11-04 07:00'));
        $first = $requests[0]->fresh();

        $this->assertTrue($first->hold_expires_at->isFuture());
        $this->assertTrue($first->isExpired());
        $this->assertFalse($first->holdsTime());
        $this->assertSame('Expired', $first->statusLabel());
        $this->assertSame(BookingStatus::Requested, $first->status);
        $this->assertSame(3, PhotographyBooking::query()->expired()->count());
        $this->assertSame(0, PhotographyBooking::query()->waiting()->count());
        $this->assertSame(0, PhotographyBooking::query()->holdingTime()->count());

        // They no longer count: Ana can send a new request
        $this->assertFalse(app(BookingService::class)->atRequestLimit($this->ana));
        $this->assertSame(BookingStatus::Requested, $this->place($this->ana, '2026-11-06 09:00')->status);

        // Both sides read "Expired" and the sentence; the owner's Expired filter lists exactly these
        $this->actingAs($this->ana)->get(route('customer.bookings.show', $first))->assertOk()
            ->assertSee('Expired')->assertSee(PhotographyBooking::EXPIRED_NOTE);
        $this->actingAs($this->ana)->get(route('customer.bookings.index'))->assertOk()->assertSee('Expired');
        $this->actingAs($this->owner)->get(route('owner.bookings.show', $first))->assertOk()
            ->assertSee('Expired')->assertSee('This request was not confirmed before its time.');
        $list = $this->actingAs($this->owner)->get(route('owner.bookings.index', ['status' => 'all', 'expired' => 1]))->assertOk();
        foreach ($requests as $request) {
            $list->assertSee($request->reference_number);
        }
        // (The new request's reference may be in the notification bell, so the listed rows are compared instead)
        $this->assertEqualsCanonicalizing($requests->pluck('id')->all(), $list->viewData('bookings')->pluck('id')->all());
        $this->assertSame(3, $list->viewData('expiredCount'));

        // It can never be confirmed; the owner rejects it, or the customer cancels it
        $this->assertSame(BookingTransitionException::timePassed()->getMessage(), $this->refusal(fn () => $this->transitions()->confirm($first, $this->owner)));
        $this->transitions()->reject($first, $this->owner, 'The day has passed.');
        $this->transitions()->cancel($requests[1]->fresh(), $this->ana);
        $this->assertSame([BookingStatus::Rejected, BookingStatus::Cancelled], [$first->fresh()->status, $requests[1]->fresh()->status]);
    }

    public function test_a_proposal_whose_proposed_time_passed_is_expired_too(): void
    {
        $request = $this->place($this->ana, '2026-11-05 14:00');
        $this->transitions()->propose($request, $this->owner, $this->manila('2026-11-04 10:00'), 'A day earlier?');

        $this->travelTo($this->manila('2026-11-04 10:30'));
        $request->refresh();

        // Its first time (Thursday) is still ahead, but the PROPOSED time is what counts
        $this->assertTrue($request->isExpired());
        $this->assertFalse($request->holdsTime());
        $this->assertSame(0, PhotographyBooking::query()->waiting()->count());
        $this->assertFalse(app(BookingService::class)->atRequestLimit($this->ana));
    }

    // One studio, and the daily limit

    public function test_sessions_at_once_is_fixed_at_one_and_cannot_be_sent_from_the_editor(): void
    {
        $this->actingAs($this->owner)->post(route('owner.schedule.settings'), [
            'buffer_minutes' => 10, 'min_notice_days' => 1, 'max_advance_days' => 30, 'request_hold_hours' => 24, 'max_sessions_per_day' => 4, 'concurrent_sessions' => 3,
        ])->assertSessionHasNoErrors();

        $row = DB::table('booking_settings')->where('id', 1)->first();
        $this->assertSame([1, 24, 4], [(int) $row->concurrent_sessions, (int) $row->request_hold_hours, (int) $row->max_sessions_per_day]);
        $this->assertSame(1, BookingSetting::current()->concurrent_sessions);

        $html = $this->actingAs($this->owner)->get(route('owner.schedule.edit'))->assertOk()->getContent();
        $this->assertStringNotContainsString('concurrent_sessions', $html);
        $this->assertStringContainsString('Maximum photoshoots per day', $html);
        $this->assertStringContainsString('How long a request holds its time', $html);

        // An empty daily limit means no limit
        $this->actingAs($this->owner)->post(route('owner.schedule.settings'), [
            'buffer_minutes' => 10, 'min_notice_days' => 1, 'max_advance_days' => 30, 'request_hold_hours' => 24, 'max_sessions_per_day' => '',
        ])->assertSessionHasNoErrors();
        $this->assertNull(BookingSetting::current()->max_sessions_per_day);

        // The request hold of a new request follows the setting
        $this->assertTrue($this->place($this->ana, '2026-11-10 11:00')->fresh()->hold_expires_at->eq(Carbon::now('UTC')->addHours(24)));
    }

    public function test_a_day_at_the_daily_limit_is_fully_booked_and_bookings_that_hold_time_count(): void
    {
        $this->setLimit(2);
        $this->confirmedAt('2026-11-03 09:00');
        $this->assertContains('14:00', $this->times(60, '2026-11-03'));

        // A held request is the second photoshoot of the day
        $this->place($this->ana, '2026-11-03 11:00');

        $this->assertSame([], $this->times(60, '2026-11-03'));
        $day = app(AvailabilityService::class)->overview(60, '2026-11-03', '2026-11-04');
        $this->assertSame([AvailabilityService::FULL, AvailabilityService::OPEN], [$day['2026-11-03']['state'], $day['2026-11-04']['state']]);
        $this->assertSame('Fully booked.', AvailabilityService::dayText($day['2026-11-03']['state']));
        $this->assertSame(BookingService::SLOT_TAKEN_MESSAGE, $this->refusal(fn () => $this->place($this->ben, '2026-11-03 14:00')));
        $this->assertSame(BookingStatus::Requested, $this->place($this->ben, '2026-11-04 14:00')->status);

        // The studio may not propose a time on the full day either
        $bens = PhotographyBooking::query()->where('customer_id', $this->ben->id)->sole();
        $this->assertSame(BookingTransitionException::slotNotAvailable()->getMessage(), $this->refusal(fn () => $this->transitions()->propose($bens, $this->owner, $this->manila('2026-11-03 15:00'), 'Tuesday?')));
    }

    public function test_the_daily_limit_counts_the_manila_day_not_the_utc_day(): void
    {
        $this->setLimit(1);
        // Wednesday 7:00 AM in Manila is still Tuesday 23:00 in UTC
        $early = PhotographyBooking::factory()->status(BookingStatus::Confirmed)->at($this->manila('2026-11-04 07:00'), 60)->create();
        $this->assertSame('2026-11-03', $early->starts_at->format('Y-m-d'));

        $days = app(AvailabilityService::class)->overview(60, '2026-11-03', '2026-11-04');

        $this->assertSame(AvailabilityService::OPEN, $days['2026-11-03']['state']);
        $this->assertSame(AvailabilityService::FULL, $days['2026-11-04']['state']);
        $this->assertFalse(app(AvailabilityService::class)->dayIsFull($this->manila('2026-11-03 23:30')));
        $this->assertTrue(app(AvailabilityService::class)->dayIsFull($this->manila('2026-11-04 00:00')));
    }

    public function test_a_walk_in_over_the_daily_limit_needs_the_tick_and_never_overrides_a_clash(): void
    {
        $this->setLimit(1);
        $this->confirmedAt('2026-11-03 10:00');
        $payload = fn (array $overrides) => array_replace([
            'counter_token' => (string) Str::uuid(), 'new_customer_name' => 'Lola Walk-in', 'package' => $this->packages[60]->id, 'custom_theme_text' => 'Plain wall',
            'date' => '2026-11-03', 'time' => '14:00', 'group_size' => 2, 'contact_number' => '0917 555 0101',
        ], $overrides);
        $send = fn (array $overrides = []) => $this->actingAs($this->owner)->post(route('owner.bookings.walk-in.store'), $payload($overrides));

        // The counter still lists the day's free times, marked as over the limit
        $this->actingAs($this->owner)->getJson(route('owner.bookings.walk-in.slots', ['package' => $this->packages[60]->id, 'date' => '2026-11-03']))
            ->assertOk()->assertJsonPath('over_limit', true)->assertJsonFragment(['value' => '14:00', 'label' => '2:00 PM', 'ends' => '3:00 PM']);

        $send()->assertSessionHasErrors(['starts_at' => 'The day already has 1 photoshoot (the daily limit). Tick "Over the daily limit, book anyway" to book it all the same.']);
        // "Confirm anyway" is about the hours and blocked days, not the limit
        $send(['outside_schedule' => '1'])->assertSessionHasErrors('starts_at');
        $this->assertSame(1, PhotographyBooking::count());
        $this->assertSame(0, User::query()->where('is_walk_in', true)->count());

        // The tick never sets a clash aside
        $send(['over_daily_limit' => '1', 'outside_schedule' => '1', 'time' => '10:30'])
            ->assertSessionHasErrors(['starts_at' => BookingTransitionException::conflict()->getMessage()]);
        $this->assertSame(1, PhotographyBooking::count());

        $send(['over_daily_limit' => '1'])->assertSessionHasNoErrors();
        $booking = PhotographyBooking::query()->latest('id')->first();
        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertStringContainsString('Booked over the daily limit.', $booking->statusHistories()->sole()->note);

        // A walk-in never takes a time a customer's request holds either
        $this->setLimit(null);
        $this->place($this->ana, '2026-11-05 11:00');
        $send(['date' => '2026-11-05', 'time' => '11:00', 'outside_schedule' => '1', 'over_daily_limit' => '1'])
            ->assertSessionHasErrors(['starts_at' => BookingTransitionException::conflict()->getMessage()]);
    }

    public function test_confirming_a_request_on_a_full_day_needs_confirm_anyway(): void
    {
        $request = PhotographyBooking::factory()->status(BookingStatus::Requested)->forCustomer($this->ana)->at($this->manila('2026-11-04 14:00'))
            ->create(['photography_package_id' => $this->packages[60]->id, 'estimated_price' => '1500.00']);
        $this->confirmedAt('2026-11-04 10:00');
        $this->setLimit(1);

        $this->assertStringContainsString('The day already has 1 photoshoot (the daily limit).', $this->refusal(fn () => $this->transitions()->confirm($request, $this->owner)));
        $this->transitions()->confirm($request, $this->owner, outsideSchedule: true);
        $this->assertSame(BookingStatus::Confirmed, $request->fresh()->status);
    }

    // The day states and the timeline of the wizard

    public function test_the_three_day_states_and_the_slot_list_never_tell_who_booked(): void
    {
        // Thursday is taken from 10:00 to 5:00 PM: one hour is left, long enough for 60 minutes only
        PhotographyBooking::factory()->status(BookingStatus::Confirmed)->forCustomer($this->ben)->at($this->manila('2026-11-05 10:00'), 420)->create();
        // Friday is taken all day
        PhotographyBooking::factory()->status(BookingStatus::Confirmed)->forCustomer($this->ben)->at($this->manila('2026-11-06 09:00'), 480)->create();

        $days = app(AvailabilityService::class)->overview(120, '2026-11-05', '2026-11-08');
        $this->assertSame(
            ['No time long enough for this package on this day.', 'Fully booked.', null, 'No photoshoots on this day.'],
            [
                AvailabilityService::dayText($days['2026-11-05']['state']), AvailabilityService::dayText($days['2026-11-06']['state']),
                $days['2026-11-07']['state'] === AvailabilityService::OPEN ? null : 'not open', AvailabilityService::dayText($days['2026-11-08']['state']),
            ],
        );
        $this->assertSame(['09:00'], $this->times(60, '2026-11-05'));

        $response = $this->actingAs($this->ana)->getJson(route('customer.bookings.slots', ['package' => $this->packages[120]->id, 'from' => '2026-11-05', 'to' => '2026-11-08']))->assertOk();
        $response->assertJsonPath('info.2026-11-05.text', 'No time long enough for this package on this day.')
            ->assertJsonPath('info.2026-11-06.text', 'Fully booked.')
            ->assertJsonPath('info.2026-11-08.text', 'No photoshoots on this day.')
            ->assertJsonPath('info.2026-11-08.timeline', null)
            ->assertJsonPath('info.2026-11-07.state', 'open')
            ->assertJsonPath('info.2026-11-05.timeline.from', '9:00 AM')
            ->assertJsonPath('info.2026-11-05.timeline.to', '5:00 PM')
            ->assertJsonPath('info.2026-11-05.timeline.busy', [['left' => 12.5, 'width' => 87.5, 'from' => '10:00 AM', 'to' => '5:00 PM']])
            ->assertJsonPath('days.2026-11-07.0.time', '9:00 AM')
            ->assertJsonPath('days.2026-11-07.0.ends', '11:00 AM')
            ->assertJsonPath('days.2026-11-07.0.period', 'Morning')
            ->assertJsonPath('not_shown', 'Times that are already requested or booked are not shown.');
        $this->assertSame(['Morning', 'Afternoon'], collect($response->json('days.2026-11-07'))->pluck('period')->unique()->values()->all());

        // Never another customer's name, reference or id
        foreach (['Ben', 'Reyes', 'PB-', 'customer'] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }

        $page = $this->actingAs($this->ana)->get(route('customer.bookings.create', ['package' => $this->packages[120]->slug]))->assertOk();
        $page->assertSee('Times that are already requested or booked are not shown.')->assertSee('Not available')->assertSee('ends ');
    }

    // People

    public function test_a_package_for_exactly_two_people_refuses_any_other_number_for_customers_and_walk_ins(): void
    {
        $couple = PhotographyPackage::factory()->published()->create(['name' => 'Couple Session', 'duration_minutes' => 60, 'min_people' => 2, 'max_people' => 2, 'price' => '2000.00', 'price_requires_confirmation' => false]);
        $family = PhotographyPackage::factory()->published()->create(['name' => 'Family Portrait', 'duration_minutes' => 60, 'min_people' => 2, 'max_people' => 6, 'price' => '3000.00', 'price_requires_confirmation' => false]);

        $this->assertSame('Couple Session is for 2 people.', BookingService::groupSizeMessage($couple));
        $this->assertSame('Family Portrait is for 2 to 6 people.', BookingService::groupSizeMessage($family));
        $this->assertSame(['2 people', '2-6 people', 'Up to 6 people'], [$couple->fitsText(), $family->fitsText(), $this->packages[60]->fitsText()]);

        foreach ([1, 3] as $people) {
            $this->assertSame('Couple Session is for 2 people.', $this->refusal(fn () => $this->place($this->ana, '2026-11-10 10:00', people: $people, package: $couple)));
        }
        $this->assertSame('Family Portrait is for 2 to 6 people.', $this->refusal(fn () => $this->place($this->ana, '2026-11-10 10:00', people: 1, package: $family)));
        $this->assertSame('Family Portrait is for 2 to 6 people.', $this->refusal(fn () => $this->place($this->ana, '2026-11-10 10:00', people: 7, package: $family)));
        $this->assertSame(0, PhotographyBooking::count());

        // The wizard's own form request says the same, next to the field
        $this->actingAs($this->ana)->from(route('customer.bookings.create'))->post(route('customer.bookings.store'), [
            'package' => $couple->id, 'group_size' => 1, 'contact_number' => '0917 123 4567', 'starts_at' => $this->manila('2026-11-10 10:00')->format('Y-m-d\TH:i:s\Z'),
            'custom_theme_text' => 'Plain wall', 'prints' => 'digital',
        ])->assertSessionHasErrors(['group_size' => 'Couple Session is for 2 people.']);

        // The walk-in form uses the same rule and words
        $walkIn = fn (int $people, PhotographyPackage $package) => $this->actingAs($this->owner)->post(route('owner.bookings.walk-in.store'), [
            'counter_token' => (string) Str::uuid(), 'new_customer_name' => 'Lola Walk-in', 'package' => $package->id, 'custom_theme_text' => 'Plain wall',
            'date' => '2026-11-10', 'time' => '13:00', 'group_size' => $people, 'contact_number' => '0917 555 0101',
        ]);
        $walkIn(3, $couple)->assertSessionHasErrors(['group_size' => 'Couple Session is for 2 people.']);
        $walkIn(1, $family)->assertSessionHasErrors(['group_size' => 'Family Portrait is for 2 to 6 people.']);
        $this->assertSame(0, PhotographyBooking::count());

        $this->assertSame(2, $this->place($this->ana, '2026-11-10 10:00', people: 2, package: $couple)->group_size);
        $walkIn(2, $couple)->assertSessionHasNoErrors();
        $this->assertSame(2, PhotographyBooking::count());

        // The wizard starts the field at the minimum and fixes it when min = max; the public cards say who it is for
        $html = $this->actingAs($this->ana)->get(route('customer.bookings.create', ['package' => $couple->slug]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/id="group_size"[^>]*value="2"[^>]*min="2"[^>]*max="2"[^>]*readonly/s', $html);
        $this->assertStringContainsString('Couple Session is for 2 people.', $html);
        $this->get(route('packages.index'))->assertOk()->assertSee('2 people')->assertSee('2-6 people')->assertSee('Up to 6 people');
        $this->get(route('packages.show', $couple->slug))->assertOk()->assertSee('2 people')->assertDontSee('Up to 2 people');
    }

    public function test_the_package_form_saves_the_fewest_people_and_refuses_more_than_the_most(): void
    {
        $package = $this->packages[60];
        $form = fn (array $overrides) => array_replace([
            'name' => $package->name, 'slug' => $package->slug, 'category' => $package->category, 'short_description' => $package->short_description,
            'duration_minutes' => 60, 'max_people' => 6, 'is_published' => '1',
        ], $overrides);

        $this->actingAs($this->owner)->put(route('owner.packages.update', $package), $form(['min_people' => 7]))
            ->assertSessionHasErrors(['min_people' => 'The fewest people cannot be more than the most people.']);
        $this->actingAs($this->owner)->put(route('owner.packages.update', $package), $form(['min_people' => 0]))->assertSessionHasErrors('min_people');
        $this->assertSame(1, $package->fresh()->min_people);

        $this->actingAs($this->owner)->put(route('owner.packages.update', $package), $form(['min_people' => 2]))->assertSessionHasNoErrors();
        $this->assertSame([2, 6], [$package->fresh()->min_people, $package->fresh()->max_people]);

        // A form that sends no minimum keeps the saved one
        $this->actingAs($this->owner)->put(route('owner.packages.update', $package), $form([]))->assertSessionHasNoErrors();
        $this->assertSame(2, $package->fresh()->min_people);
        $this->actingAs($this->owner)->get(route('owner.packages.edit', $package))->assertOk()->assertSee('Fewest people in the shoot');
    }

    // Photoshoot hours are not the shop's opening hours

    public function test_only_the_photoshoot_hours_drive_the_slots_and_the_shop_hours_are_a_display_text(): void
    {
        $this->actingAs($this->owner)->post(route('owner.settings.business'), ['shop_name' => 'Jhunlea', 'opening_hours' => 'Mon-Sun 7:00 AM - 9:00 PM'])->assertSessionHasNoErrors();

        // The shop is open 7 AM to 9 PM every day; photoshoots stay Monday to Saturday 9 to 5
        $this->assertSame(['09:00', '16:00'], [$this->times(60, '2026-11-03')[0], collect($this->times(60, '2026-11-03'))->last()]);
        $this->assertSame([], $this->times(60, '2026-11-08'));
        $this->assertContains('There are no photoshoots on Sundays.', app(AvailabilityService::class)->ruleViolations(60, $this->manila('2026-11-08 10:00')));

        // Shorter photoshoot hours change the slots; the shop hours text does not move
        AvailabilityRule::query()->where('weekday', 2)->update(['opens_at' => '13:00:00', 'closes_at' => '16:00:00']);
        $this->assertSame(['13:00', '13:30', '14:00', '14:30', '15:00'], $this->times(60, '2026-11-03'));

        $this->get(route('packages.index'))->assertOk()->assertSee('Mon-Sun 7:00 AM - 9:00 PM');
        $this->actingAs($this->owner)->get(route('owner.schedule.edit'))->assertOk()->assertSee('Weekly photoshoot hours')->assertSee('Save photoshoot hours')->assertDontSee('Weekly opening hours');
        $this->actingAs($this->owner)->get(route('owner.settings.index'))->assertOk()->assertSee('Shop opening hours')->assertSee('Weekly photoshoot hours');
        $this->actingAs($this->owner)->post(route('owner.settings.business'), ['opening_hours' => str_repeat('a', 201)])->assertSessionHasErrors('opening_hours');

        // Lumi is told both, each under its own name, and never works out "open now" from the owner's text
        $knowledge = app(KnowledgeBuilder::class)->stable();
        $this->assertStringContainsString("SHOP OPENING HOURS (as the owner wrote them; give them as written)\nMon-Sun 7:00 AM - 9:00 PM", $knowledge);
        $this->assertStringContainsString('PHOTOSHOOT HOURS (Philippine time; when a photoshoot can be booked, NOT the shop\'s opening hours)', $knowledge);
        $this->assertStringContainsString('A new request holds its time for 48 hours', $knowledge);
        $this->assertStringNotContainsString("\nOPENING HOURS (Philippine time)", $knowledge);

        $this->travelTo($this->manila('2026-11-05 14:00'));
        $this->assertSame("The shop's opening hours, as the owner wrote them: Mon-Sun 7:00 AM - 9:00 PM. It is inside the photoshoot hours now, until 5:00 PM.", app(LiveFacts::class)->openText());
        $this->assertSame(
            'Our shop hours: Mon-Sun 7:00 AM - 9:00 PM. Photoshoot hours (Philippine time): Monday 9:00 AM to 5:00 PM; Tuesday 1:00 PM to 4:00 PM; Wednesday to Saturday 9:00 AM to 5:00 PM; Sunday closed.',
            app(CodeAnswers::class)->answer('What are your opening hours?'),
        );

        // Without the text the weekly hours are the only hours known, as before
        app(ShopSettingsService::class)->saveBusiness(['shop_name' => 'Jhunlea', 'address' => null, 'phone' => null, 'email' => null, 'opening_hours' => null]);
        $this->assertSame('The shop is open now, until 5:00 PM.', app(LiveFacts::class)->openText());
    }

    // Schedule changes warn about bookings that hold time, and change none

    public function test_schedule_changes_warn_about_held_requests_and_change_no_booking(): void
    {
        $held = $this->place($this->ana, '2026-11-07 15:00');
        $proposal = $this->place($this->ben, '2026-11-10 10:00');
        $this->transitions()->propose($proposal, $this->owner, $this->manila('2026-11-07 10:00'), 'Saturday instead?');
        $before = PhotographyBooking::query()->orderBy('id')->get()->map->getAttributes()->all();

        $hours = [];
        foreach (range(0, 6) as $weekday) {
            $hours[$weekday] = $weekday === 0 ? ['open' => '0'] : ['open' => '1', 'opens' => '09:00', 'closes' => '17:00'];
        }
        $hours[6] = ['open' => '0'];
        $references = [$proposal->reference_number, $held->reference_number];

        // No photoshoots on Saturdays any more: the held request and the held PROPOSED time are both listed
        $this->actingAs($this->owner)->from(route('owner.schedule.edit'))->post(route('owner.schedule.hours'), ['hours' => $hours])
            ->assertSessionHas('error')
            ->assertSessionHas('impact', fn ($impact) => $impact['needsTick'] && array_column($impact['confirmed'], 'reference') === $references);
        $this->assertTrue(AvailabilityRule::query()->where('weekday', 6)->value('is_open'));

        $this->actingAs($this->owner)->from(route('owner.schedule.edit'))->post(route('owner.schedule.block'), ['from' => '2026-11-07'])
            ->assertSessionHas('impact', fn ($impact) => $impact['needsTick'] && array_column($impact['confirmed'], 'reference') === $references);
        $this->assertSame(0, DB::table('blocked_dates')->count());

        $this->actingAs($this->owner)->from(route('owner.schedule.edit'))->post(route('owner.schedule.settings'), [
            'buffer_minutes' => 0, 'min_notice_days' => 1, 'max_advance_days' => 30, 'request_hold_hours' => 48, 'max_sessions_per_day' => 1,
        ])->assertSessionHas('impact', fn ($impact) => $impact['needsTick'] && array_column($impact['confirmed'], 'reference') === $references);
        $this->assertNull(BookingSetting::current()->max_sessions_per_day);

        // Saved with the tick: every booking is exactly as it was
        $this->actingAs($this->owner)->from(route('owner.schedule.edit'))->post(route('owner.schedule.hours'), ['hours' => $hours, 'acknowledge' => '1'])->assertSessionHas('status');
        $this->actingAs($this->owner)->from(route('owner.schedule.edit'))->post(route('owner.schedule.block'), ['from' => '2026-11-07', 'acknowledge' => '1'])->assertSessionHas('status');
        $this->assertFalse(AvailabilityRule::query()->where('weekday', 6)->value('is_open'));
        $this->assertSame($before, PhotographyBooking::query()->orderBy('id')->get()->map->getAttributes()->all());

        // A request whose hold ran out is information only: no tick needed
        $this->travel(49)->hours();
        $this->actingAs($this->owner)->from(route('owner.schedule.edit'))->post(route('owner.schedule.block'), ['from' => '2026-11-06', 'to' => '2026-11-07'])
            ->assertSessionHas('status')
            ->assertSessionHas('impact', fn ($impact) => ! $impact['needsTick'] && $impact['confirmed'] === [] && count($impact['pending']) === 2);
    }

    // The migration's own data steps

    public function test_the_migration_gives_old_open_requests_a_hold_only_when_ahead_and_not_already_held(): void
    {
        $at = fn (string $manila, BookingStatus $status) => PhotographyBooking::factory()->status($status)->at($this->manila($manila))->create();
        $confirmed = $at('2026-11-10 09:00', BookingStatus::Confirmed);
        $clashes = $at('2026-11-10 09:30', BookingStatus::Requested);
        $oldest = $at('2026-11-10 13:00', BookingStatus::Requested);
        $second = $at('2026-11-10 13:00', BookingStatus::Requested);
        $this->travelTo($this->manila('2026-11-09 08:00'));
        $passed = $at('2026-11-08 10:00', BookingStatus::Requested);
        $proposal = $at('2026-11-12 10:00', BookingStatus::Proposed);
        $proposal->forceFill(['proposed_starts_at' => $this->manila('2026-11-10 15:00')])->save();
        DB::table('booking_settings')->update(['concurrent_sessions' => 3]);
        DB::table('shop_settings')->delete();
        $before = PhotographyBooking::query()->orderBy('id')->get(['id', 'status', 'starts_at', 'ends_at', 'confirmed_price'])->toArray();

        $migration = require database_path('migrations/2026_11_16_100000_add_scheduling_holds_and_limits.php');
        foreach (['holdExistingRequests', 'prefillOpeningHours'] as $step) {
            (new \ReflectionMethod($migration, $step))->invoke($migration);
        }

        $until = Carbon::now('UTC')->addHours(48)->format('Y-m-d H:i:s');
        $holds = fn (PhotographyBooking $booking) => DB::table('photography_bookings')->where('id', $booking->id)->value('hold_expires_at');
        $this->assertSame([$until, $until, null, null, null, null], [$holds($oldest), $holds($proposal), $holds($second), $holds($clashes), $holds($passed), $holds($confirmed)]);
        $this->assertSame($before, PhotographyBooking::query()->orderBy('id')->get(['id', 'status', 'starts_at', 'ends_at', 'confirmed_price'])->toArray());
        $this->assertSame('Mon-Sat 9:00 AM - 5:00 PM; Sun closed', DB::table('shop_settings')->where('id', 1)->value('opening_hours'));

        // An hours text the owner already has is never overwritten
        DB::table('shop_settings')->update(['opening_hours' => 'By appointment']);
        (new \ReflectionMethod($migration, 'prefillOpeningHours'))->invoke($migration);
        $this->assertSame('By appointment', DB::table('shop_settings')->where('id', 1)->value('opening_hours'));
    }
}
