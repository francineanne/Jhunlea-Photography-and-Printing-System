<?php

namespace Tests\Feature\Reports;

use App\Enums\BookingStatus;
use App\Enums\PrintingOrderStatus as Status;
use App\Enums\RecordSource;
use App\Http\Controllers\Owner\IncomeReportController;
use App\Models\Payment;
use App\Models\PhotographyBooking;
use App\Models\PhotographyPackage;
use App\Models\PrintingOrder;
use App\Models\PrintingService;
use App\Models\User;
use App\Services\PaymentLedgerService;
use App\Services\Reports\IncomePeriod;
use App\Services\Reports\IncomeReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Phase 8C: the income report (service, periods, page, end-of-day summary, CSV). Today is Thu Nov 12, 2026, Manila. */
class IncomeReportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $customer;

    private PrintingService $service;

    private PhotographyPackage $package;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-12 10:00', 'Asia/Manila')->utc());
        $this->owner = User::factory()->owner()->create(['name' => 'Jhun Owner']);
        $this->customer = User::factory()->create(['name' => 'Ana Reyes']);
        $this->service = PrintingService::factory()->published()->create(['name' => 'Document Printing']);
        $this->package = PhotographyPackage::factory()->published()->create(['name' => 'Graduation Package']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function manila(string $time): Carbon
    {
        return Carbon::parse($time, 'Asia/Manila')->utc();
    }

    private function order(string $price, RecordSource $source = RecordSource::Online, Status $status = Status::Processing): PrintingOrder
    {
        $order = PrintingOrder::factory()->status($status)->forCustomer($this->customer)->create(['confirmed_price' => $price, 'printing_service_id' => $this->service->id]);
        $order->forceFill(['source' => $source])->save();

        return $order;
    }

    private function booking(string $price, RecordSource $source = RecordSource::Online): PhotographyBooking
    {
        $booking = PhotographyBooking::factory()->status(BookingStatus::Confirmed)->forCustomer($this->customer)->create(['confirmed_price' => $price, 'photography_package_id' => $this->package->id]);
        $booking->forceFill(['source' => $source])->save();

        return $booking;
    }

    private function paid(PrintingOrder|PhotographyBooking $record, string $at, bool $gcash = false, bool $voided = false): Payment
    {
        $factory = Payment::factory()->paying($record);
        $factory = $gcash ? $factory->gcash('GC-'.$record->getMorphClass()[0].$record->id.'-'.random_int(100, 999)) : $factory;
        $factory = $voided ? $factory->voided('Wrong order.') : $factory;

        return $factory->create(['received_at' => $this->manila($at), 'received_by' => $this->owner->id]);
    }

    /** A known day (Wed Nov 11): online printing cash 100.25, walk-in printing GCash 200.50, online photo cash 1000, walk-in photo GCash 2500, a voided 999.99. */
    private function fixtures(): void
    {
        $this->paid($this->order('100.25'), '2026-11-11 09:15');
        $this->paid($this->order('200.50', RecordSource::WalkIn), '2026-11-11 11:40', gcash: true);
        $this->paid($this->booking('1000.00'), '2026-11-11 14:05');
        $this->paid($this->booking('2500.00', RecordSource::WalkIn), '2026-11-11 16:30', gcash: true);
        $this->paid($this->order('999.99'), '2026-11-11 17:00', voided: true);
        $this->order('50.00');                         // awaiting payment (an order)
        $this->booking('750.00');                      // awaiting payment (a booking)
        $this->order('80.00', status: Status::Cancelled); // closed, never awaiting
    }

    private function build(string $view, ?string $date = null, ?string $from = null, ?string $to = null): array
    {
        return app(IncomeReportService::class)->build(IncomePeriod::resolve($view, $date, $from, $to, Carbon::parse('2026-11-12', 'Asia/Manila')));
    }

    public function test_the_service_totals_match_a_hand_calculation(): void
    {
        $this->fixtures();
        $r = $this->build('daily', '2026-11-11');

        $this->assertSame(380075, $r['cents']); // 100.25 + 200.50 + 1000 + 2500
        $this->assertSame(4, $r['count']);
        $this->assertSame(95019, $r['average']); // 3800.75 / 4 = 950.1875
        $this->assertSame(['count' => 2, 'cents' => 110025], $r['methods']['cash']);
        $this->assertSame(['count' => 2, 'cents' => 270050], $r['methods']['gcash']);
        $this->assertSame(['count' => 2, 'cents' => 110025], $r['sources']['online']);
        $this->assertSame(['count' => 2, 'cents' => 270050], $r['sources']['walk_in']);
        $this->assertSame(['count' => 2, 'cents' => 30075], $r['kinds']['printing_order']);
        $this->assertSame(['count' => 2, 'cents' => 350000], $r['kinds']['photography_booking']);
        $this->assertSame(['count' => 1, 'cents' => 99999], $r['voided']);
        // 999.99 is unpaid again after its void, plus 50 and 750; the cancelled 80 never waits
        $this->assertSame(['count' => 3, 'cents' => 99999 + 5000 + 75000], $r['awaiting']);
        $this->assertSame([['kind' => 'photography_booking', 'name' => 'Graduation Package', 'count' => 2, 'cents' => 350000], ['kind' => 'printing_order', 'name' => 'Document Printing', 'count' => 2, 'cents' => 30075]], $r['items']['rows']);
        $this->assertSame(['items' => 0, 'count' => 0, 'cents' => 0], $r['items']['other']);
        $hours = collect($r['series'])->keyBy('key');
        $this->assertSame(10025, $hours['09']['cents']);
        $this->assertSame(250000, $hours['16']['cents']);
        $this->assertSame(0, $hours['17']['cents']); // the voided one
        $this->assertSame(380075, collect($r['series'])->sum('cents'));
    }

    public function test_top_items_keep_ten_and_group_the_rest_as_other(): void
    {
        foreach (range(1, 12) as $i) {
            $service = PrintingService::factory()->published()->create(['name' => 'Service '.$i]);
            $order = PrintingOrder::factory()->status(Status::Processing)->create(['confirmed_price' => (100 + $i).'.00', 'printing_service_id' => $service->id]);
            $this->paid($order, '2026-11-12 09:00');
        }

        $items = $this->build('daily')['items'];
        $this->assertCount(10, $items['rows']);
        $this->assertSame('Service 12', $items['rows'][0]['name']);
        $this->assertSame(['items' => 2, 'count' => 2, 'cents' => 10100 + 10200], $items['other']);
    }

    public function test_manila_days_weeks_and_months(): void
    {
        // 23:59 on Nov 10 and 00:00 on Nov 11 in Manila are both on Nov 10 in UTC
        $late = $this->paid($this->order('10.00'), '2026-11-10 23:59');
        $early = $this->paid($this->order('20.00'), '2026-11-11 00:00');
        $this->assertSame($late->received_at->format('Y-m-d'), $early->received_at->format('Y-m-d'));

        $this->assertSame(1000, $this->build('daily', '2026-11-10')['cents']);
        $this->assertSame(2000, $this->build('daily', '2026-11-11')['cents']);
        $this->assertSame(1000, collect($this->build('daily', '2026-11-10')['series'])->firstWhere('key', '23')['cents']);
        $this->assertSame(2000, collect($this->build('daily', '2026-11-11')['series'])->firstWhere('key', '00')['cents']);

        $today = Carbon::parse('2026-11-12', 'Asia/Manila');
        $week = IncomePeriod::resolve('weekly', '2026-11-08', null, null, $today); // a Sunday
        $this->assertSame(['2026-11-02', '2026-11-08', '2026-11-09'], [$week->from, $week->to, $week->nextDate]);
        $this->assertSame(['from' => '2026-10-26', 'to' => '2026-11-01'], array_intersect_key($week->previous, ['from' => 1, 'to' => 1]));
        $this->assertSame(3000, $this->build('weekly', '2026-11-11')['cents']);
        $this->assertCount(7, $this->build('weekly', '2026-11-11')['series']);

        $leap = IncomePeriod::resolve('monthly', '2024-02-10', null, null, $today);
        $this->assertSame(['2024-02-01', '2024-02-29'], [$leap->from, $leap->to]);
        $this->assertSame('2025-02-28', IncomePeriod::resolve('monthly', '2025-02-27', null, null, $today)->to);
        $march = IncomePeriod::resolve('monthly', '2024-03-31', null, null, $today);
        $this->assertSame(['from' => '2024-02-01', 'to' => '2024-02-29'], array_intersect_key($march->previous, ['from' => 1, 'to' => 1]));
        $this->assertSame('2024-04-01', $march->nextDate);

        // Never past the current period: a future date shows today, and there is no next
        foreach (['daily', 'weekly', 'monthly'] as $view) {
            $period = IncomePeriod::resolve($view, '2027-01-05', null, null, $today);
            $this->assertNull($period->nextDate, $view);
            $this->assertTrue($period->isCurrent, $view);
        }
    }

    public function test_the_report_total_equals_the_ledger_total_for_every_view(): void
    {
        $this->fixtures();
        $this->paid($this->order('33.33'), '2026-11-02 08:00');
        $this->paid($this->order('44.44'), '2026-10-31 20:00');
        $this->paid($this->booking('55.55'), '2025-12-24 18:00', gcash: true);
        $ledger = app(PaymentLedgerService::class);
        $today = Carbon::parse('2026-11-12', 'Asia/Manila');

        foreach ([['daily', '2026-11-11', null, null], ['weekly', '2026-11-11', null, null], ['monthly', '2026-11-11', null, null], ['monthly', '2026-10-05', null, null],
            ['custom', null, '2025-12-01', '2026-11-02'], ['all', null, null, null]] as [$view, $date, $from, $to]) {
            $period = IncomePeriod::resolve($view, $date, $from, $to, $today);
            $report = app(IncomeReportService::class)->build($period);
            $ledgerTotals = $ledger->totals($period->filters());
            $listed = $ledger->listing($period->filters())->get()->filter->isRecorded()->sum(fn (Payment $payment) => (int) round($payment->amount * 100));

            $this->assertSame($ledgerTotals['cents'], $report['cents'], $view);
            $this->assertSame($listed, $report['cents'], $view);
            $this->assertSame($report['cents'], collect($report['series'])->sum('cents'), $view.' series');
            $this->assertSame($report['cents'], $report['sources']['online']['cents'] + $report['sources']['walk_in']['cents'], $view.' sources');
        }

        // And on the page, against the ledger page for the same dates
        $page = $this->actingAs($this->owner)->get(route('owner.reports.income', ['view' => 'monthly', 'date' => '2026-11-11']))->assertOk();
        $ledgerPage = $this->actingAs($this->owner)->get(route('owner.payments.index', $page->viewData('period')->ledgerQuery()))->assertOk();
        $this->assertSame($ledgerPage->viewData('totals')['cents'], $page->viewData('report')['cents']);
        $page->assertSee('Report total and ledger total are the same for these dates.');
    }

    public function test_the_previous_period_comparison(): void
    {
        $this->paid($this->order('100.00'), '2026-11-11 10:00');
        $this->paid($this->order('150.00'), '2026-11-12 09:00');

        $this->assertSame(['label' => 'the day before', 'cents' => 10000, 'count' => 1, 'change' => 5000, 'percent' => 50.0, 'direction' => 'up'], $this->build('daily')['comparison']);
        $this->assertSame(['label' => 'the day before', 'cents' => 0, 'count' => 0, 'change' => 10000, 'percent' => null, 'direction' => 'up'], $this->build('daily', '2026-11-11')['comparison']);
        $this->assertNull($this->build('all')['comparison']);

        $this->actingAs($this->owner)->get(route('owner.reports.income', ['view' => 'daily', 'date' => '2026-11-11']))
            ->assertSee('No income in the previous period (the day before).');
        $this->actingAs($this->owner)->get(route('owner.reports.income', ['view' => 'daily']))
            ->assertSee('Up ₱50.00 (50%) from the day before (₱100.00).');
    }

    public function test_invalid_values_fall_back_to_the_default_view(): void
    {
        foreach ([['view' => 'yearly'], ['date' => '2026-02-30'], ['view' => ['daily']], ['from' => '2026-11-05', 'to' => '2026-11-01'], ['page' => 'x']] as $query) {
            $this->actingAs($this->owner)->get(route('owner.reports.income', $query))->assertRedirect(route('owner.reports.income'))->assertSessionHasErrors();
        }

        $this->followingRedirects()->actingAs($this->owner)->get(route('owner.reports.income', ['view' => 'yearly']))->assertOk()
            ->assertSee('Your usual view is shown.')->assertViewHas('period', fn (IncomePeriod $p) => $p->view === 'daily' && $p->date === '2026-11-12');
        $this->actingAs($this->owner)->get(route('owner.reports.end-of-day', ['date' => '2026-11-13']))->assertRedirect()->assertSessionHasErrors('date');
    }

    public function test_the_chosen_view_is_remembered_per_owner(): void
    {
        $other = User::factory()->owner()->create();
        $this->assertSame('daily', $this->owner->fresh()->report_view);

        $this->actingAs($this->owner)->get(route('owner.reports.income', ['view' => 'monthly']))->assertOk();
        $this->assertSame('monthly', $this->owner->fresh()->report_view);
        $this->actingAs($this->owner)->get(route('owner.reports.income'))->assertViewHas('period', fn (IncomePeriod $p) => $p->view === 'monthly' && $p->from === '2026-11-01');

        // Custom and all time are not remembered; another owner keeps their own
        $this->actingAs($this->owner)->get(route('owner.reports.income', ['view' => 'all']))->assertOk();
        $this->actingAs($this->owner)->get(route('owner.reports.income', ['from' => '2026-11-01', 'to' => '2026-11-03']))->assertOk();
        $this->assertSame('monthly', $this->owner->fresh()->report_view);
        $this->actingAs($other)->get(route('owner.reports.income'))->assertViewHas('period', fn (IncomePeriod $p) => $p->view === 'daily');
    }

    public function test_owner_only(): void
    {
        foreach ([route('owner.reports.income'), route('owner.reports.end-of-day'), route('owner.reports.csv')] as $url) {
            $this->actingAs($this->customer)->get($url)->assertForbidden();
            auth()->logout();
            $this->get($url)->assertRedirect(route('login'));
        }
    }

    public function test_the_csv(): void
    {
        $order = $this->order('1850.00', RecordSource::WalkIn);
        $this->paid($order, '2026-11-12 09:30');
        $this->customer->forceFill(['name' => '=HYPERLINK("http://x")'])->save();
        $voided = $this->booking('2500.00');
        $this->paid($voided, '2026-11-12 09:45', gcash: true, voided: true);
        $this->paid($this->order('10.00'), '2026-11-11 09:00'); // the day before: not in today's file

        $response = $this->actingAs($this->owner)->get(route('owner.reports.csv', ['view' => 'daily']))->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('jhunlea-income-2026-11-12-to-2026-11-12.csv', $response->headers->get('Content-Disposition'));
        $csv = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $rows = array_map('str_getcsv', explode("\n", trim(substr($csv, 3))));
        $this->assertSame(IncomeReportController::CSV_COLUMNS, $rows[0]);
        $this->assertCount(3, $rows);
        $this->assertSame(['2026-11-12', '09:30', '\'=HYPERLINK("http://x")', $order->reference_number, 'Printing order', $order->serviceName(), 'Walk-in', 'Cash', '', '1850.00', 'Recorded', 'Jhun Owner'], array_slice($rows[1], 1));
        $this->assertSame(['Photoshoot', (string) ($voided->snapshot['package']['name'] ?? 'Photoshoot'), 'Online', 'GCash'], array_slice($rows[2], 5, 4));
        $this->assertSame(['2500.00', 'Voided'], array_slice($rows[2], 10, 2));
        $this->assertSame("'-1", IncomeReportController::cell('-1'));
        $this->assertSame("'@x", IncomeReportController::cell('@x'));
        $this->assertSame('Ana', IncomeReportController::cell('Ana'));
    }

    public function test_the_page_queries_do_not_grow_with_the_rows(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->owner)->get(route('owner.reports.income', ['view' => 'monthly']))->assertOk();

            return count(DB::getQueryLog());
        };

        $this->paid($this->order('10.00'), '2026-11-03 09:00');
        $this->paid($this->booking('20.00', RecordSource::WalkIn), '2026-11-04 09:00', gcash: true);
        $few = $count();

        foreach (range(1, 6) as $i) {
            $this->paid($this->order('10.00', RecordSource::WalkIn), '2026-11-0'.$i.' 10:00');
            $this->paid($this->booking('20.00'), '2026-11-0'.$i.' 11:00', gcash: true);
            $this->paid($this->order('5.00'), '2026-11-0'.$i.' 12:00', voided: true);
        }

        $this->assertSame($few, $count());
    }

    public function test_the_end_of_day_summary(): void
    {
        $this->fixtures();

        $html = $this->actingAs($this->owner)->get(route('owner.reports.end-of-day', ['date' => '2026-11-11']))->assertOk()
            ->assertSee('End-of-day summary')->assertSee('Wednesday, November 11, 2026')
            ->assertSee('Prepared from the system’s payment records. Not an official document.')
            ->assertSee('₱3,800.75')->assertSee('Voided payments (not counted)')->assertSee('VOID · Wrong order.')
            ->assertSee('Cash expected in the drawer: the Cash total above (₱1,100.25).')
            ->assertDontSee('fonts.googleapis', false)->assertDontSee('<script', false)
            ->getContent();

        // The voided payment is only in the voided table, never in the day's list or the total
        $recordedTable = substr($html, strpos($html, 'Payments of the day'), strpos($html, 'Voided payments (not counted)') - strpos($html, 'Payments of the day'));
        $this->assertStringNotContainsString('999.99', $recordedTable);

        $this->actingAs($this->owner)->get(route('owner.reports.end-of-day'))->assertOk()->assertSee('No payments were recorded on this day.');
    }

    public function test_the_page_and_its_empty_state(): void
    {
        $this->actingAs($this->owner)->get(route('owner.reports.income'))->assertOk()
            ->assertSee('No payments in this period')->assertSee('Income and payments')->assertSee('End-of-day summary')
            ->assertDontSee('Revenue')->assertDontSee('Sales')->assertDontSee('Profit');

        $this->fixtures();
        $this->actingAs($this->owner)->get(route('owner.reports.income', ['view' => 'daily', 'date' => '2026-11-11']))->assertOk()
            ->assertDontSee('No payments in this period')->assertSee('₱3,800.75')->assertSee('Voided · not counted')
            ->assertSee('Graduation Package')->assertSee(route('owner.payments.index', ['from' => '2026-11-11', 'to' => '2026-11-11']))
            ->assertSee('1 voided payment (₱999.99) is listed but not counted.');

        // The Requests report keeps working beside it, and the sidebar opens the Income report
        $this->actingAs($this->owner)->get(route('owner.reports.index'))->assertOk()->assertSee(route('owner.reports.income'), false);
    }
}
