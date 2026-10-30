<?php

namespace Tests\Feature\Owner;

use App\Enums\BookingStatus as B;
use App\Enums\PrintingOrderStatus as O;
use App\Models\Payment;
use App\Models\PhotographyBooking;
use App\Models\PhotographyPackage;
use App\Models\PrintingOrder;
use App\Models\PrintingService;
use App\Models\User;
use App\Services\PaymentLedgerService;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Phase 6A: the owner's reports, every number against a hand calculation, on the shop's (Manila) calendar days. */
class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        // Wednesday 4 November 2026, 12:00 in Manila
        $this->travelTo(Carbon::parse('2026-11-04 04:00:00', 'UTC'));
        $this->owner = User::factory()->owner()->create();
    }

    private function manila(string $local): Carbon
    {
        return Carbon::parse($local, 'Asia/Manila')->utc();
    }

    private function order(O $status, string $created, array $attributes = []): PrintingOrder
    {
        return PrintingOrder::factory()->status($status)->create(['created_at' => $this->manila($created), ...$attributes]);
    }

    private function booking(B $status, string $created, array $attributes = []): PhotographyBooking
    {
        return PhotographyBooking::factory()->status($status)->at($this->manila('2026-11-20 10:00'))->create(['created_at' => $this->manila($created), ...$attributes]);
    }

    private function report(array $query = [])
    {
        return $this->actingAs($this->owner)->get(route('owner.reports.index', $query));
    }

    /** November 2026 (this month): orders and bookings, with ones just outside on both Manila edges. */
    private function busy(): void
    {
        $this->order(O::Submitted, '2026-10-31 23:30');                                     // October in Manila (31 Oct 15:30 UTC)
        $this->order(O::Submitted, '2026-11-01 00:30');                                     // November in Manila (31 Oct 16:30 UTC)
        $this->order(O::Accepted, '2026-11-02 10:00', ['confirmed_price' => '150.00', 'estimated_price' => '9.00']);
        $paid = $this->order(O::Completed, '2026-11-03 10:00', ['confirmed_price' => '80.00']);
        $this->order(O::Rejected, '2026-11-04 11:00');

        $this->booking(B::Requested, '2026-11-01 00:30');
        $this->booking(B::Confirmed, '2026-11-02 10:00', ['confirmed_price' => '1200.00', 'estimated_price' => '9999.00']);
        $this->booking(B::Requested, '2026-10-31 23:30');

        // Payments: 80.00 cash on 1 Nov 00:30; 50.50 GCash on 3 Nov; 999.00 on 31 Oct 23:30 (last month); a voided 40.00
        Payment::factory()->paying($paid)->create(['amount' => '80.00', 'received_at' => $this->manila('2026-11-01 00:30')]);
        $other = $this->order(O::Completed, '2026-09-01 10:00', ['confirmed_price' => '50.50']);
        Payment::factory()->paying($other)->gcash('GC-777777')->create(['amount' => '50.50', 'received_at' => $this->manila('2026-11-03 11:00')]);
        $old = $this->order(O::Completed, '2026-09-02 10:00', ['confirmed_price' => '999.00']);
        Payment::factory()->paying($old)->create(['amount' => '999.00', 'received_at' => $this->manila('2026-10-31 23:30')]);
        $void = $this->order(O::Processing, '2026-09-03 10:00', ['confirmed_price' => '40.00']);
        Payment::factory()->paying($void)->voided()->create(['amount' => '40.00', 'received_at' => $this->manila('2026-11-02 10:00')]);
    }

    public function test_owner_only(): void
    {
        $this->actingAs(User::factory()->create())->get(route('owner.reports.index'))->assertForbidden();
        auth()->logout();
        $this->get(route('owner.reports.index'))->assertRedirect(route('login'));
    }

    public function test_this_month_by_default_matches_a_hand_calculation(): void
    {
        $this->busy();
        $response = $this->report()->assertOk()->assertViewHas('period', fn ($p) => $p['range'] === 'this_month' && $p['from'] === '2026-11-01' && $p['to'] === '2026-11-04');
        $r = $response->viewData('report');

        // Orders created 1 to 4 November in Manila: not the 23:30 one on 31 October; the September ones neither
        $orders = collect($r['orderStatuses']['rows'])->mapWithKeys(fn ($row) => [$row['status']->value => $row['count']]);
        $this->assertSame(4, $r['orderStatuses']['total']);
        $this->assertSame(['submitted' => 1, 'accepted' => 1, 'rejected' => 1, 'completed' => 1], $orders->filter()->all());

        $bookings = collect($r['bookingStatuses']['rows'])->mapWithKeys(fn ($row) => [$row['status']->value => $row['count']]);
        $this->assertSame(2, $r['bookingStatuses']['total']);
        $this->assertSame(['requested' => 1, 'confirmed' => 1], $bookings->filter()->all());

        // Recorded in November: 80.00 cash (00:30 on the 1st) and 50.50 GCash; not 999.00 (31 Oct 23:30), not the voided 40.00
        $this->assertSame(13050, $r['recorded']['cents']);
        $this->assertSame(2, $r['recorded']['count']);
        $this->assertSame(8000, $r['recorded']['methods']['cash']['cents']);
        $this->assertSame(5050, $r['recorded']['methods']['gcash']['cents']);
        $this->assertSame(app(PaymentLedgerService::class)->totals(['method' => null, 'status' => null, 'search' => '', 'from' => '2026-11-01', 'to' => '2026-11-04']), $r['recorded']);

        // Awaiting right now, any date: 150.00 + 1,200.00 + the voided order's 40.00 again; never the estimates
        $this->assertSame(15000 + 120000 + 4000, $r['awaiting']['cents']);
        $this->assertSame(3, $r['awaiting']['count']);
        $this->assertSame(app(PaymentLedgerService::class)->awaiting()['cents'], $r['awaiting']['cents']);

        $response->assertSee('₱130.50')->assertSee('₱1,390.00')
            ->assertSee('Recorded payments are what the shop actually received. Estimates are never counted.')
            ->assertSee('Awaiting payment right now')->assertSee('not limited to the period', false);
    }

    public function test_last_month_all_time_and_a_custom_period(): void
    {
        $this->busy();

        $last = $this->report(['range' => 'last_month'])->assertOk()->viewData('report');
        $this->assertSame(1, $last['orderStatuses']['total']);    // 31 October 23:30
        $this->assertSame(1, $last['bookingStatuses']['total']);
        $this->assertSame(99900, $last['recorded']['cents']);
        // Awaiting is the same whatever the period
        $this->assertSame(139000, $last['awaiting']['cents']);

        $all = $this->report(['range' => 'all'])->assertOk()->assertViewHas('period', fn ($p) => $p['from'] === null && $p['to'] === null)->viewData('report');
        $this->assertSame(8, $all['orderStatuses']['total']);
        $this->assertSame(3, $all['bookingStatuses']['total']);
        $this->assertSame(8000 + 5050 + 99900, $all['recorded']['cents']);

        // Just the Manila day of 1 November: the 00:30 order and booking and the 00:30 payment
        $day = $this->report(['from' => '2026-11-01', 'to' => '2026-11-01'])->assertOk()
            ->assertViewHas('period', fn ($p) => $p['range'] === 'custom' && $p['label'] === 'Nov 1, 2026 to Nov 1, 2026')->viewData('report');
        $this->assertSame(1, $day['orderStatuses']['total']);
        $this->assertSame(1, $day['bookingStatuses']['total']);
        $this->assertSame(8000, $day['recorded']['cents']);

        $seven = $this->report(['range' => 'last_7'])->assertOk()->assertViewHas('period', fn ($p) => $p['from'] === '2026-10-29' && $p['to'] === '2026-11-04');
        $this->assertSame(5, $seven->viewData('report')['orderStatuses']['total']);
        $this->report(['range' => 'last_30'])->assertOk()->assertViewHas('period', fn ($p) => $p['from'] === '2026-10-06' && $p['to'] === '2026-11-04');
    }

    public function test_an_empty_period_lists_every_status_at_zero(): void
    {
        $this->busy();
        $response = $this->report(['from' => '2030-01-01', 'to' => '2030-01-31'])->assertOk();
        $r = $response->viewData('report');

        $this->assertSame(array_map(fn ($s) => $s->value, O::cases()), array_map(fn ($row) => $row['status']->value, $r['orderStatuses']['rows']));
        $this->assertSame(array_map(fn ($s) => $s->value, B::cases()), array_map(fn ($row) => $row['status']->value, $r['bookingStatuses']['rows']));
        $this->assertSame(0, $r['orderStatuses']['total'] + $r['bookingStatuses']['total']);
        $this->assertTrue(collect($r['orderStatuses']['rows'])->every(fn ($row) => $row['count'] === 0));
        $this->assertSame(0, $r['recorded']['cents']);
        $this->assertCount(0, $r['topServices']);
        $this->assertSame(139000, $r['awaiting']['cents']);

        foreach ([...O::cases(), ...B::cases()] as $status) {
            $response->assertSee($status->label());
        }
        $response->assertSee('No printing orders in this period.')->assertSee('No photoshoot requests in this period.');
    }

    public function test_popular_lists_use_the_snapshot_name_are_limited_to_five_and_break_ties_by_id(): void
    {
        $services = PrintingService::factory()->published()->count(7)->create();
        $make = fn (PrintingService $service, int $count, string $name) => PrintingOrder::factory()->count($count)->create([
            'printing_service_id' => $service->id, 'created_at' => $this->manila('2026-11-02 10:00'),
            'snapshot' => ['service' => ['name' => $name], 'options' => [], 'pages' => 1, 'unit_price' => '5.00'],
        ]);

        $make($services[0], 1, 'Single');
        $make($services[1], 3, 'Tie B');
        $make($services[2], 3, 'Tie A later id');
        $make($services[3], 5, 'Old name');
        $make($services[3], 1, 'Renamed in the snapshot');
        $make($services[4], 2, 'Two');
        $make($services[5], 2, 'Two again');
        $make($services[6], 1, 'Left out');
        $services[3]->delete();

        $top = app(ReportService::class)->build('2026-11-01', '2026-11-30')['topServices'];

        // 6 for the removed service (its latest snapshot name), the two 3s by lower service id, the two 2s by lower id
        $this->assertSame([
            ['name' => 'Renamed in the snapshot', 'count' => 6],
            ['name' => 'Tie B', 'count' => 3],
            ['name' => 'Tie A later id', 'count' => 3],
            ['name' => 'Two', 'count' => 2],
            ['name' => 'Two again', 'count' => 2],
        ], $top->all());
        $this->assertSame($top->all(), app(ReportService::class)->build('2026-11-01', '2026-11-30')['topServices']->all());

        $package = PhotographyPackage::factory()->published()->create(['name' => 'Live package name']);
        PhotographyBooking::factory()->count(2)->at($this->manila('2026-11-20 10:00'))->create([
            'photography_package_id' => $package->id, 'created_at' => $this->manila('2026-11-02 10:00'),
            'snapshot' => ['package' => ['name' => 'Name when booked'], 'theme' => null],
        ]);
        $package->delete();

        $this->assertSame([['name' => 'Name when booked', 'count' => 2]], app(ReportService::class)->build('2026-11-01', '2026-11-30')['topPackages']->all());
        $this->report()->assertOk()->assertSee('Renamed in the snapshot')->assertSee('Name when booked')->assertDontSee('Left out')->assertDontSee('Live package name');
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function badPeriods(): array
    {
        return [
            'unknown range' => [['range' => 'forever']],
            'array range' => [['range' => ['all']]],
            'impossible date' => [['from' => '2026-02-30']],
            'other format' => [['from' => '11/01/2026']],
            'end before start' => [['from' => '2026-11-05', 'to' => '2026-11-01']],
            'too early' => [['from' => '1999-12-31']],
            'too late' => [['to' => '2101-01-01']],
            'array date' => [['from' => ['2026-11-01']]],
        ];
    }

    #[DataProvider('badPeriods')]
    public function test_a_bad_period_falls_back_to_this_month_with_a_message(array $query): void
    {
        $this->report($query)->assertRedirect(route('owner.reports.index'))->assertSessionHasErrors();

        $this->followingRedirects()->actingAs($this->owner)->get(route('owner.reports.index', $query))->assertOk()
            ->assertSee('This month is shown.')->assertViewHas('period', fn ($p) => $p['range'] === 'this_month');
    }

    public function test_the_queries_are_fixed_however_much_data_exists(): void
    {
        $count = function (array $query = []): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->report($query)->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->busy();
        $few = $count();
        $allFew = $count(['range' => 'all']);

        foreach (range(1, 15) as $i) {
            $order = $this->order(O::Completed, '2026-11-0'.(1 + $i % 4).' 10:00', ['confirmed_price' => '10.00', 'printing_service_id' => PrintingService::factory()->published()]);
            Payment::factory()->paying($order)->create(['amount' => '10.00', 'received_at' => $this->manila('2026-11-02 10:00')]);
            $this->booking(B::Requested, '2026-11-02 10:00', ['photography_package_id' => PhotographyPackage::factory()->published()]);
        }

        $this->assertSame($few, $count());
        $this->assertSame($allFew, $count(['range' => 'all']));
    }

    public function test_money_words_and_escaping(): void
    {
        $this->busy();
        PrintingOrder::factory()->create([
            'created_at' => $this->manila('2026-11-02 10:00'),
            'snapshot' => ['service' => ['name' => '<script>alert(1)</script>'], 'options' => [], 'pages' => 1, 'unit_price' => '5.00'],
        ]);

        $html = $this->report()->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->getContent();

        $main = substr($html, strpos($html, '<main'));
        foreach (['revenue', 'sales', 'profit', 'income', 'average order', 'growth'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $main, $word);
        }
        $this->assertMatchesRegularExpression('/href="'.preg_quote(route('owner.reports.index'), '/').'"\s+aria-current="page"/', $html);
    }
}
