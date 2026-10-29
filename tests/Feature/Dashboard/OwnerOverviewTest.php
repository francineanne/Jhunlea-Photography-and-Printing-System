<?php

namespace Tests\Feature\Dashboard;

use App\Enums\BookingStatus as B;
use App\Enums\PrintingOrderStatus as O;
use App\Models\Payment;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Models\User;
use App\Services\DashboardService;
use App\Services\PaymentLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Phase 5C: the owner overview, every number against a hand calculation, owner only. */
class OwnerOverviewTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        // Wednesday 4 November 2026, 12:00 in Manila
        $this->travelTo(Carbon::parse('2026-11-04 04:00:00', 'UTC'));
        $this->owner = User::factory()->owner()->create(['name' => 'Jhun Arceo']);
    }

    private function manila(string $local): Carbon
    {
        return Carbon::parse($local, 'Asia/Manila')->utc();
    }

    private function shoot(B $status, string $start, ?string $price = null): PhotographyBooking
    {
        return PhotographyBooking::factory()->status($status)->at($this->manila($start))->create(['confirmed_price' => $price, 'estimated_price' => '9999.00']);
    }

    private function busy(): void
    {
        PrintingOrder::factory()->count(2)->status(O::Submitted)->create();
        PrintingOrder::factory()->status(O::UnderReview)->create();
        PrintingOrder::factory()->status(O::Accepted)->create(['confirmed_price' => '150.00', 'estimated_price' => '9.00']);
        $paid = PrintingOrder::factory()->status(O::ReadyForPickup)->create(['confirmed_price' => '80.00']);
        PrintingOrder::factory()->status(O::Completed)->create(['confirmed_price' => '70.00']);

        $this->shoot(B::Requested, '2026-11-09 10:00');
        $this->shoot(B::Requested, '2026-11-10 10:00');
        $this->shoot(B::Confirmed, '2026-11-04 15:00', '1200.00');
        $this->shoot(B::Confirmed, '2026-11-04 09:00', '0.00');
        $this->shoot(B::Confirmed, '2026-11-05 00:30', '500.00');   // tomorrow in Manila (still 4 November in UTC)
        $this->shoot(B::Confirmed, '2026-11-03 23:30', '300.00');   // yesterday in Manila

        // Recorded payments: 23:30 yesterday and 00:30 today in Manila; a voided one today
        Payment::factory()->paying($paid)->create(['amount' => '80.00', 'received_at' => $this->manila('2026-11-04 00:30')]);
        $other = PrintingOrder::factory()->status(O::Completed)->create(['confirmed_price' => '50.50']);
        Payment::factory()->paying($other)->gcash('GC-777777')->create(['amount' => '50.50', 'received_at' => $this->manila('2026-11-04 11:00')]);
        $old = PrintingOrder::factory()->status(O::Completed)->create(['confirmed_price' => '999.00']);
        Payment::factory()->paying($old)->create(['amount' => '999.00', 'received_at' => $this->manila('2026-11-03 23:30')]);
        $void = PrintingOrder::factory()->status(O::Processing)->create(['confirmed_price' => '40.00']);
        Payment::factory()->paying($void)->voided()->create(['amount' => '40.00', 'received_at' => $this->manila('2026-11-04 10:00')]);
    }

    public function test_a_fresh_install_shows_zeros_and_empty_states(): void
    {
        $this->actingAs($this->owner)->get(route('owner.dashboard'))->assertOk()
            ->assertSee('Good afternoon, Jhun')->assertDontSee('Dashboard')
            ->assertSee('No printing orders are waiting for review.')->assertSee('No photoshoot requests are waiting.')
            ->assertSee('No confirmed photoshoots today.')->assertSee('Nothing is waiting for payment.')
            ->assertViewHas('data', fn ($d) => $d['reviewCount'] === 0 && $d['requestCount'] === 0 && $d['shootCount'] === 0
                && $d['awaiting']['count'] === 0 && $d['recordedToday']['cents'] === 0 && $d['unread'] === 0);
    }

    public function test_every_number_matches_a_hand_calculation(): void
    {
        $this->busy();
        $d = app(DashboardService::class)->owner($this->owner);

        $this->assertSame(3, $d['reviewCount']);
        $this->assertSame(2, $d['requestCount']);
        // Today in Manila: 09:00 and 15:00, in time order; not 00:30 tomorrow or 23:30 yesterday
        $this->assertSame(2, $d['shootCount']);
        $this->assertSame(['09:00', '15:00'], $d['shoots']->map(fn ($s) => $s->starts_at->copy()->setTimezone('Asia/Manila')->format('H:i'))->all());

        // Awaiting payment is the ledger's own result: 150.00 (order) + 1200 + 500 + 300 (confirmed shoots); the voided order (40) again too
        $this->assertSame(app(PaymentLedgerService::class)->awaiting()['cents'], $d['awaiting']['cents']);
        $this->assertSame(15000 + 120000 + 50000 + 30000 + 4000, $d['awaiting']['cents']);
        $this->assertSame(5, $d['awaiting']['count']);

        // Recorded today (Manila): cash 80.00 at 00:30 and GCash 50.50; not 999.00 at 23:30 yesterday, not the voided 40.00
        $this->assertSame(13050, $d['recordedToday']['cents']);
        $this->assertSame(8000, $d['recordedToday']['methods']['cash']['cents']);
        $this->assertSame(5050, $d['recordedToday']['methods']['gcash']['cents']);

        $this->actingAs($this->owner)->get(route('owner.dashboard'))->assertOk()
            ->assertSee('Recorded payments today')->assertSee('₱130.50')->assertSee('Cash ₱80.00 · GCash ₱50.50')
            ->assertDontSee('Revenue')->assertDontSee('Sales');
    }

    public function test_the_queries_are_fixed_however_much_data_exists(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->owner)->get(route('owner.dashboard'))->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->busy();
        $few = $count();
        PrintingOrder::factory()->count(15)->status(O::Submitted)->create();
        foreach (range(1, 12) as $i) {
            $this->shoot(B::Requested, '2026-11-20 10:00');
            $this->shoot(B::Confirmed, '2026-11-04 1'.($i % 6).':00', '10.00');
        }

        $this->assertSame($few, $count());
    }

    public function test_owner_only(): void
    {
        $this->actingAs(User::factory()->create())->get(route('owner.dashboard'))->assertForbidden();
        auth()->logout();
        $this->get(route('owner.dashboard'))->assertRedirect(route('login'));
    }

    public function test_long_and_hostile_names_are_escaped(): void
    {
        $customer = User::factory()->create(['name' => '<img src=x onerror=alert(1)>'.str_repeat('Long', 40)]);
        PrintingOrder::factory()->forCustomer($customer)->status(O::Submitted)->create();
        $this->owner->forceFill(['name' => '<b>Jhun</b> Arceo'])->save();

        $this->actingAs($this->owner)->get(route('owner.dashboard'))->assertOk()
            ->assertDontSee('<img src=x onerror=alert(1)>', false)->assertDontSee('<b>Jhun</b>', false);
    }
}
