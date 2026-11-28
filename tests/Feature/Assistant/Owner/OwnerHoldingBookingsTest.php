<?php

namespace Tests\Feature\Assistant\Owner;

use App\Enums\BookingStatus;
use App\Enums\RecordSource;
use App\Models\PhotographyBooking;
use App\Models\User;
use App\Services\Assistant\Owner\OwnerTools;
use App\Services\Assistant\Owner\ToolResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Phase 10T: the owner's Ask Lumi counts every photoshoot that holds its time, awaiting downpayment included, and says
 * what is still to pay. Clock: Thursday 2026-11-05 10:00 Manila; tomorrow is Friday 2026-11-06.
 */
class OwnerHoldingBookingsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-05 10:00', 'Asia/Manila'));
        $this->owner = User::factory()->owner()->create();
    }

    private function tool(string $tool, array $params = []): ToolResult
    {
        return app(OwnerTools::class)->get($tool)->execute($params, $this->owner);
    }

    private function booking(BookingStatus $status, string $manila, array $attributes = []): PhotographyBooking
    {
        return PhotographyBooking::factory()->status($status)->at(Carbon::parse($manila, 'Asia/Manila')->utc())->create($attributes);
    }

    /** Tomorrow: one confirmed, one awaiting its downpayment, one request; today: one request whose time passed. */
    private function seedTomorrow(): void
    {
        $this->booking(BookingStatus::Confirmed, '2026-11-06 09:00', ['confirmed_price' => '1000.00', 'reference_number' => 'PB-000001']);
        $this->booking(BookingStatus::AwaitingDownpayment, '2026-11-06 13:00', ['confirmed_price' => '1200.00', 'reference_number' => 'PB-000002',
            'downpayment_due_at' => Carbon::parse('2026-11-06 08:00', 'Asia/Manila')->utc()]);
        $this->booking(BookingStatus::Requested, '2026-11-06 15:00', ['reference_number' => 'PB-000003', 'hold_expires_at' => now()->addHours(48)]);
        // Expired: a request whose time passed this morning without an answer
        $this->booking(BookingStatus::Requested, '2026-11-05 08:00', ['reference_number' => 'PB-000004']);
    }

    public function test_the_default_counts_every_session_that_holds_its_time_and_names_the_unpaid_one(): void
    {
        $this->seedTomorrow();

        $result = $this->tool('bookings_schedule', ['from' => '2026-11-06', 'to' => '2026-11-06']);

        $this->assertSame(2, $result->total);
        $this->assertSame(['PB-000001', 'PB-000002'], array_column($result->rows, 'reference'));
        $this->assertSame(1, $result->data['confirmed']);
        $this->assertSame(1, $result->data['awaiting_downpayment']);
        $this->assertSame(0, $result->data['completed']);
        $this->assertSame(1, $result->data['waiting_requests']);

        [$confirmed, $awaiting] = $result->rows;
        $this->assertSame(BookingStatus::AwaitingDownpayment->label(), $awaiting['status']);
        // 50% of ₱1,200.00 (the default rule), due by 8:00 AM on the day
        $this->assertSame('₱600.00', $awaiting['downpayment_due']);
        $this->assertStringContainsString('8:00 AM', $awaiting['downpayment_deadline']);
        $this->assertArrayNotHasKey('downpayment_due', $confirmed);
        $this->assertArrayNotHasKey('downpayment_deadline', $confirmed);
    }

    public function test_the_old_confirmed_value_is_the_same_as_the_new_default(): void
    {
        $this->seedTomorrow();
        $tomorrow = ['from' => '2026-11-06', 'to' => '2026-11-06'];

        $old = $this->tool('bookings_schedule', $tomorrow + ['status' => 'confirmed']);
        $new = $this->tool('bookings_schedule', $tomorrow + ['status' => 'holding']);

        $this->assertSame($new->total, $old->total);
        $this->assertSame($new->rows, $old->rows);
        $this->assertSame($new->data, $old->data);
    }

    public function test_waiting_and_all_leave_out_an_expired_request(): void
    {
        $this->seedTomorrow();
        $tomorrow = ['from' => '2026-11-06', 'to' => '2026-11-06'];
        $bothDays = ['from' => '2026-11-05', 'to' => '2026-11-06'];

        $this->assertSame(3, $this->tool('bookings_schedule', $tomorrow + ['status' => 'all'])->total);
        $this->assertSame(['PB-000003'], array_column($this->tool('bookings_schedule', $tomorrow + ['status' => 'waiting'])->rows, 'reference'));

        // Today's expired request is in the period but never counted, in any view
        $this->assertSame(3, $this->tool('bookings_schedule', $bothDays + ['status' => 'all'])->total);
        $this->assertSame(1, $this->tool('bookings_schedule', $bothDays + ['status' => 'waiting'])->total);
        $this->assertSame(1, $this->tool('bookings_schedule', $bothDays)->data['waiting_requests']);
        $this->assertNotContains('PB-000004', array_column($this->tool('bookings_schedule', $bothDays + ['status' => 'all'])->rows, 'reference'));
    }

    public function test_a_walk_in_session_today_awaiting_its_downpayment_is_not_paid_yet_in_the_briefing_and_the_counter(): void
    {
        $this->booking(BookingStatus::AwaitingDownpayment, '2026-11-05 16:00', ['confirmed_price' => '800.00', 'reference_number' => 'PB-000010',
            'downpayment_due_at' => Carbon::parse('2026-11-05 14:00', 'Asia/Manila')->utc(), 'source' => RecordSource::WalkIn]);
        $this->booking(BookingStatus::Confirmed, '2026-11-05 17:00', ['confirmed_price' => '500.00', 'reference_number' => 'PB-000011']);

        $briefing = $this->tool('briefing');
        $this->assertSame(1, $briefing->data['walk_in_sessions_today_unpaid']);
        $this->assertSame(1, $briefing->data['sessions_today']); // the dashboard's own number: confirmed only
        $this->assertSame(1, $briefing->data['sessions_today_awaiting_downpayment']);

        $counter = $this->tool('counter_queues', ['kind' => 'walk_in_today']);
        $this->assertSame(1, $counter->data['walk_in_sessions_today_unpaid']);
        $this->assertSame('PB-000010', $counter->rows[0]['reference']);
        $this->assertSame(BookingStatus::AwaitingDownpayment->label(), $counter->rows[0]['status']);
    }

    public function test_a_booking_lookup_says_what_is_due_and_by_when(): void
    {
        $this->seedTomorrow();

        $row = $this->tool('booking_lookup', ['reference' => 'PB-000002'])->data;

        $this->assertSame('₱600.00', $row['downpayment_due']);
        $this->assertStringContainsString('8:00 AM', $row['downpayment_deadline']);
    }
}
