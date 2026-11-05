<?php

namespace Tests\Feature\Assistant;

use App\Enums\BookingStatus;
use App\Models\AvailabilityRule;
use App\Models\BlockedDate;
use App\Models\PhotographyBooking;
use App\Models\User;
use App\Services\Assistant\AssistantActor;
use App\Services\Assistant\CustomerContextBuilder;
use App\Services\Assistant\LiveFacts;
use App\Services\Assistant\PromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\FakesAssistant;
use Tests\TestCase;

/**
 * Lumi knows today's date and time in Manila and whether the shop is open (Phase 7E-2a), and each booking says how far
 * its time is from now, worked out by code, with a note when a time has passed without the booking moving on. The live
 * test that found this: a booking requested for Tue Nov 3 was called upcoming on Nov 5.
 */
class TimeAwarenessTest extends TestCase
{
    use FakesAssistant, RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAssistant();
        $this->customer = User::factory()->create();

        // Monday to Saturday 9:00 AM to 6:00 PM, Sunday closed
        foreach (range(0, 6) as $weekday) {
            $rule = new AvailabilityRule(['is_open' => $weekday !== 0, 'opens_at' => '09:00:00', 'closes_at' => '18:00:00']);
            $rule->weekday = $weekday;
            $rule->save();
        }
    }

    /** Freezes the clock at a Manila wall time. */
    private function at(string $manila): void
    {
        $this->travelTo(Carbon::parse($manila, 'Asia/Manila')->utc());
    }

    private function booking(BookingStatus $status, string $manila, array $attributes = []): PhotographyBooking
    {
        static $n = 0;
        $n++;

        return PhotographyBooking::factory()->forCustomer($this->customer)->status($status)->at(Carbon::parse($manila, 'Asia/Manila')->utc())
            ->create(['reference_number' => sprintf('PB-%06d', 700 + $n)] + $attributes);
    }

    private function system(): string
    {
        $this->ask('Kailan ang shoot ko?', actor: AssistantActor::user($this->customer->id));

        return $this->fake->lastRequest()->systemInstruction;
    }

    private function line(string $system, string $reference): string
    {
        $this->assertSame(1, preg_match('/^- .*'.preg_quote($reference, '/').'.*$/m', $system, $match), "{$reference} is not in the block");

        return $match[0];
    }

    public function test_each_booking_says_how_far_its_time_is_and_flags_a_passed_one(): void
    {
        // Thursday, Nov 5, 2026, 2:00 PM in Manila
        $this->at('2026-11-05 14:00');
        // Room for all six lines (the usual cap drops the oldest finished record first)
        config(['assistant.customer_context_max_chars' => 6000]);

        $future = $this->booking(BookingStatus::Confirmed, '2026-11-08 10:00', ['confirmed_price' => '2500.00']);
        $today = $this->booking(BookingStatus::Confirmed, '2026-11-05 17:00', ['confirmed_price' => '2500.00']);
        $tomorrow = $this->booking(BookingStatus::Requested, '2026-11-06 09:00');
        $pastRequested = $this->booking(BookingStatus::Requested, '2026-11-03 10:00');
        $pastConfirmed = $this->booking(BookingStatus::Confirmed, '2026-11-04 10:00', ['confirmed_price' => '2500.00']);
        $completed = $this->booking(BookingStatus::Completed, '2026-11-01 10:00', ['confirmed_price' => '2500.00']);

        $system = $this->system();

        $line = $this->line($system, $future->reference_number);
        $this->assertStringContainsString('When: in 3 days.', $line);
        $this->assertStringNotContainsString('Note:', $line);

        $line = $this->line($system, $today->reference_number);
        $this->assertStringContainsString('When: later today.', $line);
        $this->assertStringNotContainsString('Note:', $line);

        $line = $this->line($system, $tomorrow->reference_number);
        $this->assertStringContainsString('When: tomorrow.', $line);
        $this->assertStringNotContainsString('Note:', $line);

        $line = $this->line($system, $pastRequested->reference_number);
        $this->assertStringContainsString('Requested time (not confirmed yet): Tue, Nov 3, 2026 10:00 AM.', $line);
        $this->assertStringContainsString('When: 2 days ago (already passed).', $line);
        $this->assertStringContainsString('Note: The requested time has already passed and the studio has not confirmed it; the customer should message the studio to arrange a new time.', $line);

        $line = $this->line($system, $pastConfirmed->reference_number);
        $this->assertStringContainsString('When: yesterday (already passed).', $line);
        $this->assertStringContainsString('Note: The session time has passed and the booking is not marked completed yet.', $line);

        $line = $this->line($system, $completed->reference_number);
        $this->assertStringContainsString('When: 4 days ago (already passed).', $line);
        $this->assertStringNotContainsString('Note:', $line);

        // A proposal is judged by the proposed time, not the original request (a customer of its own: the prompt lists
        // at most 5 open records)
        $this->customer = User::factory()->create();
        $pastProposal = $this->booking(BookingStatus::Proposed, '2026-11-09 10:00', ['proposed_starts_at' => Carbon::parse('2026-11-05 09:00', 'Asia/Manila')->utc()]);
        $line = $this->line($this->system(), $pastProposal->reference_number);
        $this->assertStringContainsString('When: earlier today (already passed).', $line);
        $this->assertStringContainsString('Note: The proposed time has already passed and the booking is not confirmed', $line);
    }

    public function test_days_are_counted_in_manila_not_in_utc(): void
    {
        $session = Carbon::parse('2026-11-06 09:00', 'Asia/Manila');

        // 11:30 PM Manila on Nov 5 is 3:30 PM UTC on Nov 5: the session is tomorrow
        $this->assertSame('tomorrow', CustomerContextBuilder::relative($session->copy()->utc(), Carbon::parse('2026-11-05 15:30', 'UTC')));

        // 12:30 AM Manila on Nov 6 is still Nov 5 in UTC (16:30): the session is today, and last night is yesterday
        $now = Carbon::parse('2026-11-05 16:30', 'UTC');
        $this->assertSame('later today', CustomerContextBuilder::relative($session->copy()->utc(), $now));
        $this->assertSame('yesterday (already passed)', CustomerContextBuilder::relative(Carbon::parse('2026-11-05 23:00', 'Asia/Manila')->utc(), $now));
        $this->assertSame('earlier today (already passed)', CustomerContextBuilder::relative(Carbon::parse('2026-11-06 00:10', 'Asia/Manila')->utc(), $now));

        // And through the prompt at that same minute
        $this->travelTo($now);
        $booking = $this->booking(BookingStatus::Confirmed, '2026-11-06 09:00', ['confirmed_price' => '2500.00']);
        $system = $this->system();
        $this->assertStringContainsString('When: later today.', $this->line($system, $booking->reference_number));
        $this->assertStringContainsString('NOW (Philippine time, computed by the system): Friday, November 6, 2026, 12:30 AM.', $system);
    }

    public function test_the_now_line_says_whether_the_shop_is_open(): void
    {
        $live = fn () => app(LiveFacts::class)->line();

        $this->at('2026-11-05 14:00');
        $this->assertSame('NOW (Philippine time, computed by the system): Thursday, November 5, 2026, 2:00 PM. The shop is open now, until 6:00 PM. Any date before today has already passed.', $live());

        $this->at('2026-11-05 19:15');
        $this->assertStringContainsString('7:15 PM. The shop is closed now; it next opens tomorrow at 9:00 AM.', $live());

        $this->at('2026-11-05 07:30');
        $this->assertStringContainsString('The shop is closed now; it next opens today at 9:00 AM.', $live());

        // Sunday is a day off
        $this->at('2026-11-08 10:00');
        $this->assertStringContainsString('Sunday, November 8, 2026, 10:00 AM. The shop is closed today; it next opens tomorrow at 9:00 AM.', $live());

        // A blocked Friday is a day off too, and the next opening skips a blocked Saturday as well
        BlockedDate::query()->forceCreate(['date' => '2026-11-06', 'reason' => 'Holiday']);
        BlockedDate::query()->forceCreate(['date' => '2026-11-07', 'reason' => 'Holiday']);
        $this->at('2026-11-06 10:00');
        $this->assertStringContainsString('The shop is closed today; it next opens Monday at 9:00 AM.', $live());
    }

    public function test_the_live_facts_and_the_rules_are_in_the_prompt_tail_never_in_the_cached_prefix(): void
    {
        $this->at('2026-11-05 14:00');
        $this->booking(BookingStatus::Requested, '2026-11-03 10:00');

        $this->ask('Kailan ang shoot ko?', actor: AssistantActor::user($this->customer->id));
        $request = $this->fake->lastRequest();

        $this->assertStringContainsString('NOW (Philippine time, computed by the system): Thursday, November 5, 2026, 2:00 PM.', $request->volatileSystem);
        $this->assertStringNotContainsString('NOW (Philippine time', $request->stableSystem);
        $this->assertStringNotContainsString('2026', $request->stableSystem, 'No date in the cached prefix');
        $this->assertStringEndsWith(PromptBuilder::LATEST_ONLY, $request->systemInstruction);

        $this->assertStringContainsString('Never tell the customer to wait for a time that has already passed', $request->stableSystem);
        $this->assertStringContainsString('never work out dates yourself', $request->stableSystem);
        $this->assertStringContainsString('A date before today has passed: never speak of it as upcoming.', $request->stableSystem);
    }

    public function test_a_guest_also_gets_the_now_line(): void
    {
        $this->at('2026-11-05 14:00');
        $this->ask('Open ba kayo bukas?');

        $this->assertStringContainsString('The shop is open now, until 6:00 PM.', $this->fake->lastRequest()->volatileSystem);
    }
}
