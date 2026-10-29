<?php

namespace Tests\Feature\Booking;

use App\Enums\BookingEvent;
use App\Enums\BookingStatus as Status;
use App\Enums\UserRole;
use App\Exceptions\BookingTransitionException;
use App\Models\AvailabilityRule;
use App\Models\BlockedDate;
use App\Models\BookingSetting;
use App\Models\BookingStatusHistory;
use App\Models\PhotographyBooking;
use App\Models\User;
use App\Services\BookingTransitionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BookingTransitionServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpSchedule;

    /** Written out by hand, so the test does not just echo the enum. */
    private const OWNER_MOVES = [
        'requested>confirmed', 'requested>proposed', 'requested>rejected', 'requested>cancelled',
        'proposed>rejected', 'proposed>cancelled',
        'confirmed>completed', 'confirmed>cancelled',
    ];

    private const CUSTOMER_MOVES = [
        'requested>cancelled',
        'proposed>confirmed', 'proposed>requested', 'proposed>cancelled',
        'confirmed>cancelled',
    ];

    private User $owner;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSchedule();
        $this->owner = User::factory()->owner()->create();
        $this->customer = User::factory()->create();
    }

    private function service(): BookingTransitionService
    {
        return app(BookingTransitionService::class);
    }

    private function booking(Status $status = Status::Requested, string $manilaStart = '2026-11-05 10:00', array $attributes = []): PhotographyBooking
    {
        return PhotographyBooking::factory()->forCustomer($this->customer)->status($status)->at($this->manila($manilaStart))->create($attributes);
    }

    /** Makes the move through the method the service offers for it. */
    private function attempt(PhotographyBooking $booking, Status $to, User $actor): void
    {
        $service = $this->service();
        $from = $booking->status;

        match (true) {
            $to === Status::Confirmed && $from === Status::Proposed && $actor->role === UserRole::Customer => $service->acceptProposal($booking, $actor),
            $to === Status::Confirmed => $service->confirm($booking, $actor),
            $to === Status::Proposed => $service->propose($booking, $actor, $this->manila('2026-11-06 14:00'), 'Would 2 PM work?'),
            $to === Status::Requested => $service->askForAnotherTime($booking, $actor, 'Morning please.'),
            $to === Status::Rejected => $service->reject($booking, $actor, 'Fully booked that week.'),
            $to === Status::Cancelled => $service->cancel($booking, $actor),
            $to === Status::Completed => $service->complete($booking, $actor),
        };
    }

    /** @return array<string, array{Status, Status}> */
    public static function everyPair(): array
    {
        $pairs = [];

        foreach (Status::cases() as $from) {
            foreach (Status::cases() as $to) {
                if ($from !== $to) {
                    $pairs["{$from->value} to {$to->value}"] = [$from, $to];
                }
            }
        }

        return $pairs;
    }

    #[DataProvider('everyPair')]
    public function test_the_owner_can_make_exactly_the_listed_moves(Status $from, Status $to): void
    {
        $attributes = $from === Status::Proposed ? ['proposed_starts_at' => $this->manila('2026-11-06 14:00')] : [];
        // Completing needs a session that has started, and (Phase 5A) one that is settled: a ₱0.00 price needs no payment
        $booking = $this->booking($from, $to === Status::Completed ? '2026-11-02 07:00' : '2026-11-05 10:00', $attributes + ($to === Status::Completed ? ['confirmed_price' => '0.00'] : []));
        $allowed = in_array("{$from->value}>{$to->value}", self::OWNER_MOVES, true);

        if (! $allowed) {
            $this->expectException(BookingTransitionException::class);
        }

        $this->attempt($booking, $to, $this->owner);

        $this->assertSame($to, $booking->fresh()->status);
        $this->assertSame(1, BookingStatusHistory::count());
    }

    #[DataProvider('everyPair')]
    public function test_the_customer_can_make_exactly_the_listed_moves(Status $from, Status $to): void
    {
        $attributes = $from === Status::Proposed ? ['proposed_starts_at' => $this->manila('2026-11-06 14:00')] : [];
        $booking = $this->booking($from, attributes: $attributes);
        $allowed = in_array("{$from->value}>{$to->value}", self::CUSTOMER_MOVES, true);

        if (! $allowed) {
            $this->expectException(BookingTransitionException::class);
        }

        $this->attempt($booking, $to, $this->customer);

        $this->assertSame($to, $booking->fresh()->status);
    }

    public function test_the_table_agrees_with_the_hand_written_lists(): void
    {
        $fromEnum = [];

        foreach (Status::cases() as $from) {
            foreach ($from->transitions() as $to => $roles) {
                foreach ($roles as $role) {
                    $fromEnum[$role->value][] = "{$from->value}>{$to}";
                }
            }
        }

        $this->assertEqualsCanonicalizing(self::OWNER_MOVES, $fromEnum['owner']);
        $this->assertEqualsCanonicalizing(self::CUSTOMER_MOVES, $fromEnum['customer']);
        $this->assertFalse(Status::Requested->canMoveTo(Status::Confirmed, UserRole::Customer), 'A customer never confirms their own request');
        foreach ([Status::Rejected, Status::Cancelled, Status::Completed] as $final) {
            $this->assertTrue($final->isFinal());
        }
    }

    public function test_a_customer_cannot_confirm_their_own_request(): void
    {
        $booking = $this->booking();

        $this->expectException(BookingTransitionException::class);

        $this->service()->confirm($booking, $this->customer);
    }

    public function test_confirm_defaults_the_price_to_the_estimate_or_takes_the_owners(): void
    {
        $plain = $this->booking(attributes: ['estimated_price' => '1850.00']);
        $changed = $this->booking(manilaStart: '2026-11-05 14:00', attributes: ['estimated_price' => '1850.00']);

        $this->service()->confirm($plain, $this->owner);
        $this->service()->confirm($changed, $this->owner, '2000');

        $this->assertSame('1850.00', $plain->fresh()->confirmed_price);
        $this->assertSame('2000.00', $changed->fresh()->confirmed_price);
    }

    public function test_a_package_priced_on_request_needs_a_price_to_confirm(): void
    {
        $booking = $this->booking(attributes: ['estimated_price' => null]);

        try {
            $this->service()->confirm($booking, $this->owner);
            $this->fail('Confirming without a price must be refused.');
        } catch (BookingTransitionException) {
            // expected
        }

        $this->assertSame(Status::Requested, $booking->fresh()->status);

        $this->service()->confirm($booking, $this->owner, '3500.50');
        $this->assertSame('3500.50', $booking->fresh()->confirmed_price);
    }

    public function test_a_bad_price_is_refused(): void
    {
        $booking = $this->booking();

        foreach (['-1', '1.234', 'abc', '100000000'] as $price) {
            try {
                $this->service()->confirm($booking, $this->owner, $price);
                $this->fail("Price {$price} must be refused.");
            } catch (BookingTransitionException) {
                // expected
            }
        }

        $this->assertSame(0, BookingStatusHistory::count());
    }

    public function test_confirm_refuses_a_time_that_another_confirmed_booking_holds(): void
    {
        PhotographyBooking::factory()->status(Status::Confirmed)->at($this->manila('2026-11-05 10:30'))->create();
        $booking = $this->booking();

        $this->expectException(BookingTransitionException::class);

        try {
            $this->service()->confirm($booking, $this->owner);
        } finally {
            $this->assertSame(Status::Requested, $booking->fresh()->status);
        }
    }

    public function test_two_overlapping_requests_cannot_both_be_confirmed_even_from_pages_loaded_together(): void
    {
        $first = $this->booking();
        $second = PhotographyBooking::factory()->forCustomer(User::factory()->create())->at($this->manila('2026-11-05 10:30'))->create();

        // Both owner pages were loaded while both were requests
        $staleSecond = PhotographyBooking::find($second->id);

        $this->service()->confirm($first, $this->owner);

        $this->expectException(BookingTransitionException::class);

        try {
            $this->service()->confirm($staleSecond, $this->owner);
        } finally {
            $this->assertSame(Status::Requested, $second->fresh()->status);
        }
    }

    public function test_confirming_takes_the_schedule_lock(): void
    {
        $booking = $this->booking();
        $before = (int) BookingSetting::query()->find(BookingSetting::ROW_ID)->lock_version;

        $this->service()->confirm($booking, $this->owner);

        $this->assertSame($before + 1, (int) BookingSetting::query()->find(BookingSetting::ROW_ID)->lock_version);
    }

    public function test_the_schedule_lock_creates_the_settings_row_when_it_is_missing(): void
    {
        BookingSetting::query()->delete();
        $booking = $this->booking();

        $this->service()->confirm($booking, $this->owner);

        $this->assertSame(1, (int) BookingSetting::query()->find(BookingSetting::ROW_ID)->lock_version);
    }

    public function test_reject_needs_a_reason(): void
    {
        $booking = $this->booking();

        foreach (['', '   '] as $blank) {
            try {
                $this->service()->reject($booking, $this->owner, $blank);
                $this->fail('A rejection without a reason must be refused.');
            } catch (BookingTransitionException) {
                // expected
            }
        }

        $this->service()->reject($booking, $this->owner, '  Fully booked.  ');
        $this->assertSame('Fully booked.', $booking->fresh()->rejection_reason);
    }

    public function test_propose_then_accept_moves_and_confirms_the_booking(): void
    {
        $booking = $this->booking(attributes: ['estimated_price' => '1200.00']);
        $requestedAt = $booking->requested_starts_at->copy();

        $this->service()->propose($booking, $this->owner, $this->manila('2026-11-06 14:00'), 'The morning is full.');
        $proposed = $booking->fresh();
        $this->assertSame(Status::Proposed, $proposed->status);
        $this->assertSame('2026-11-06 06:00:00', $proposed->proposed_starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('The morning is full.', $proposed->proposed_note);
        $this->assertTrue($proposed->starts_at->eq($requestedAt), 'The session does not move until the customer accepts');

        $this->service()->acceptProposal($booking, $this->customer);
        $accepted = $booking->fresh();
        $this->assertSame(Status::Confirmed, $accepted->status);
        $this->assertSame('2026-11-06 06:00:00', $accepted->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-06 07:00:00', $accepted->ends_at->format('Y-m-d H:i:s'));
        $this->assertTrue($accepted->requested_starts_at->eq($requestedAt), 'What the customer first asked for is kept');
        $this->assertNull($accepted->proposed_starts_at);
        $this->assertSame('1200.00', $accepted->confirmed_price);

        $this->assertSame([BookingEvent::ProposalSent, BookingEvent::ProposalAccepted], $booking->statusHistories->pluck('event')->all());
    }

    public function test_propose_then_ask_again_returns_to_a_request(): void
    {
        $booking = $this->booking();

        $this->service()->propose($booking, $this->owner, $this->manila('2026-11-06 14:00'), 'Would 2 PM work?');
        $this->service()->askForAnotherTime($booking, $this->customer, 'Only mornings, please.');

        $fresh = $booking->fresh();
        $this->assertSame(Status::Requested, $fresh->status);
        $this->assertNull($fresh->proposed_starts_at);
        $this->assertNull($fresh->proposed_note);
        $last = $booking->statusHistories->last();
        $this->assertSame(BookingEvent::TimeRequestedAgain, $last->event);
        $this->assertSame('Only mornings, please.', $last->note);
    }

    public function test_a_proposal_must_be_a_free_slot_with_a_note(): void
    {
        $booking = $this->booking();
        PhotographyBooking::factory()->status(Status::Confirmed)->at($this->manila('2026-11-06 14:00'))->create();

        $bad = [
            [$this->manila('2026-11-06 14:00'), 'Taken'],
            [$this->manila('2026-11-08 10:00'), 'Sunday'],
            [$this->manila('2026-11-06 16:30'), 'Runs past closing'],
            [$this->manila('2026-11-02 10:00'), 'Inside the notice'],
            [$this->manila('2026-11-06 10:00'), ''],
        ];

        foreach ($bad as [$time, $note]) {
            try {
                $this->service()->propose($booking, $this->owner, $time, $note);
                $this->fail("Proposal '{$note}' must be refused.");
            } catch (BookingTransitionException) {
                // expected
            }
        }

        $this->assertSame(Status::Requested, $booking->fresh()->status);
    }

    public function test_a_proposal_on_a_package_priced_on_request_needs_a_price(): void
    {
        $booking = $this->booking(attributes: ['estimated_price' => null]);

        try {
            $this->service()->propose($booking, $this->owner, $this->manila('2026-11-06 14:00'), 'Would 2 PM work?');
            $this->fail('A proposal without a price must be refused.');
        } catch (BookingTransitionException) {
            // expected
        }

        $this->service()->propose($booking, $this->owner, $this->manila('2026-11-06 14:00'), 'Would 2 PM work?', '2500');
        $this->service()->acceptProposal($booking, $this->customer);

        $this->assertSame('2500.00', $booking->fresh()->confirmed_price);
    }

    public function test_accepting_a_proposal_rechecks_the_time(): void
    {
        $booking = $this->booking();
        $this->service()->propose($booking, $this->owner, $this->manila('2026-11-06 14:00'), 'Would 2 PM work?');

        // Someone else's booking is confirmed at that time before the customer answers
        PhotographyBooking::factory()->status(Status::Confirmed)->at($this->manila('2026-11-06 14:00'))->create();

        $this->expectException(BookingTransitionException::class);

        try {
            $this->service()->acceptProposal($booking, $this->customer);
        } finally {
            $this->assertSame(Status::Proposed, $booking->fresh()->status);
        }
    }

    public function test_accepting_a_proposal_on_a_day_blocked_after_the_offer_is_refused(): void
    {
        $booking = $this->booking();
        $this->service()->propose($booking, $this->owner, $this->manila('2026-11-06 14:00'), 'Would 2 PM work?');

        BlockedDate::factory()->create(['date' => '2026-11-06', 'reason' => 'Studio repairs']);

        $this->assertAcceptRefusedAsNotAvailable($booking);
    }

    public function test_accepting_a_proposal_after_the_hours_changed_is_refused(): void
    {
        $booking = $this->booking();
        $this->service()->propose($booking, $this->owner, $this->manila('2026-11-06 14:00'), 'Would 2 PM work?');

        // Friday now closes at 1 PM, before the proposed 2 PM session
        AvailabilityRule::query()->where('weekday', 5)->update(['closes_at' => '13:00:00']);

        $this->assertAcceptRefusedAsNotAvailable($booking);
    }

    private function assertAcceptRefusedAsNotAvailable(PhotographyBooking $booking): void
    {
        try {
            $this->service()->acceptProposal($booking, $this->customer);
            $this->fail('Accepting a time that is no longer open must be refused.');
        } catch (BookingTransitionException $e) {
            $this->assertSame(BookingTransitionException::slotNotAvailable()->getMessage(), $e->getMessage());
        }

        $fresh = $booking->fresh();
        $this->assertSame(Status::Proposed, $fresh->status);
        $this->assertNotNull($fresh->proposed_starts_at);
        $this->assertSame(1, BookingStatusHistory::count());
    }

    public function test_the_owner_saves_an_internal_note_without_a_history_row(): void
    {
        $booking = $this->booking();

        $this->service()->saveInternalNote($booking, $this->owner, '  Regular customer, likes window light.  ');

        $this->assertSame('Regular customer, likes window light.', $booking->fresh()->internal_notes);
        $this->assertSame(0, BookingStatusHistory::count());

        $this->service()->saveInternalNote($booking, $this->owner, '   ');
        $this->assertNull($booking->fresh()->internal_notes);

        try {
            $this->service()->saveInternalNote($booking, $this->owner, str_repeat('a', 2001));
            $this->fail('A note over 2000 characters must be refused.');
        } catch (BookingTransitionException) {
            // expected
        }

        $this->service()->saveInternalNote($booking, $this->owner, str_repeat('b', 2000));
        $this->assertSame(2000, mb_strlen($booking->fresh()->internal_notes));
        $this->assertSame(0, BookingStatusHistory::count());
    }

    public function test_a_customer_cannot_save_an_internal_note(): void
    {
        $booking = $this->booking();

        try {
            $this->service()->saveInternalNote($booking, $this->customer, 'Let me in.');
            $this->fail('A customer must be refused.');
        } catch (AuthorizationException) {
            // expected
        }

        $this->assertNull($booking->fresh()->internal_notes);
        $this->assertSame(0, BookingStatusHistory::count());
    }

    public function test_internal_notes_are_never_serialized_or_filled(): void
    {
        $booking = $this->booking();
        $this->service()->saveInternalNote($booking, $this->owner, 'Owes for last shoot.');
        $fresh = $booking->fresh();

        $this->assertArrayNotHasKey('internal_notes', $fresh->toArray());
        $this->assertStringNotContainsString('Owes for last shoot.', $fresh->toJson());
        $this->assertStringNotContainsString('internal_notes', json_encode($fresh));

        $fresh->fill(['internal_notes' => 'Changed by a form']);
        $this->assertSame('Owes for last shoot.', $fresh->internal_notes);
    }

    public function test_completing_before_the_start_is_refused(): void
    {
        $booking = $this->booking(Status::Confirmed, '2026-11-02 09:00', ['confirmed_price' => '0.00']);

        try {
            $this->service()->complete($booking, $this->owner);
            $this->fail('Completing before the start must be refused.');
        } catch (BookingTransitionException) {
            // expected
        }

        $this->travelTo($this->manila('2026-11-02 09:00'));
        $this->service()->complete($booking, $this->owner);

        $this->assertSame(Status::Completed, $booking->fresh()->status);
    }

    public function test_a_customer_cancels_a_confirmed_booking_only_until_the_cutoff(): void
    {
        config(['booking.customer_cancel_cutoff_hours' => 24]);
        $early = $this->booking(Status::Confirmed, '2026-11-03 08:00'); // exactly 24 hours away
        $late = $this->booking(Status::Confirmed, '2026-11-03 07:59');

        $this->service()->cancel($early, $this->customer, 'Change of plans.');
        $this->assertSame(Status::Cancelled, $early->fresh()->status);
        $this->assertSame('Change of plans.', $early->fresh()->cancellation_reason);

        try {
            $this->service()->cancel($late, $this->customer);
            $this->fail('Cancelling inside the cutoff must be refused.');
        } catch (BookingTransitionException) {
            // expected
        }

        // The studio can still cancel it
        $this->service()->cancel($late, $this->owner, 'Studio closed.');
        $this->assertSame(Status::Cancelled, $late->fresh()->status);
    }

    public function test_another_customer_cannot_touch_the_booking(): void
    {
        $booking = $this->booking(Status::Proposed, attributes: ['proposed_starts_at' => $this->manila('2026-11-06 14:00')]);
        $stranger = User::factory()->create();

        foreach ([
            fn () => $this->service()->cancel($booking, $stranger),
            fn () => $this->service()->acceptProposal($booking, $stranger),
            fn () => $this->service()->askForAnotherTime($booking, $stranger, 'Me?'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Another customer must be refused.');
            } catch (AuthorizationException) {
                // expected
            }
        }

        $this->assertSame(Status::Proposed, $booking->fresh()->status);
        $this->assertSame(0, BookingStatusHistory::count());
    }

    public function test_a_double_submit_writes_one_history_row(): void
    {
        $booking = $this->booking();
        $stale = PhotographyBooking::find($booking->id);

        $this->service()->confirm($booking, $this->owner);

        try {
            $this->service()->confirm($stale, $this->owner);
            $this->fail('The second confirm must be refused.');
        } catch (BookingTransitionException) {
            // expected
        }

        $this->assertSame(1, BookingStatusHistory::count());
    }

    public function test_the_price_of_a_confirmed_booking_can_change_with_a_note(): void
    {
        $booking = $this->booking(Status::Confirmed, attributes: ['confirmed_price' => '1000.00']);

        $this->service()->changePrice($booking, $this->owner, '1250', 'Extra outfit.');
        $this->service()->changePrice($booking, $this->owner, '1250.00');

        $this->assertSame('1250.00', $booking->fresh()->confirmed_price);
        $row = BookingStatusHistory::sole();
        $this->assertSame(BookingEvent::PriceChanged, $row->event);
        $this->assertSame('Confirmed price changed from ₱1,000.00 to ₱1,250.00. Extra outfit.', $row->note);

        $this->expectException(AuthorizationException::class);
        $this->service()->changePrice($booking, $this->customer, '1');
    }

    public function test_the_price_cannot_change_unless_confirmed(): void
    {
        $this->expectException(BookingTransitionException::class);

        $this->service()->changePrice($this->booking(), $this->owner, '1');
    }

    public function test_every_change_writes_who_from_to_and_note(): void
    {
        $booking = $this->booking();

        $this->service()->confirm($booking, $this->owner, null, 'See you then.');

        $row = BookingStatusHistory::sole();
        $this->assertSame(Status::Requested, $row->from_status);
        $this->assertSame(Status::Confirmed, $row->to_status);
        $this->assertNull($row->event);
        $this->assertSame($this->owner->id, $row->changed_by);
        $this->assertSame('See you then.', $row->note);
        $this->assertSame('Confirmed', $row->title());
    }
}
