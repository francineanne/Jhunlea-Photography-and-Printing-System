<?php

namespace Tests\Feature\Assistant\Owner;

use App\Enums\AssistantOutcome;
use App\Models\User;
use App\Services\Assistant\Owner\DateRange;
use App\Services\Assistant\Owner\IncomeRange;
use App\Services\Assistant\Owner\NumberGuard;
use App\Services\Assistant\Owner\OwnerAskService;
use App\Services\Assistant\Owner\OwnerPromptBuilder;
use App\Services\Assistant\Owner\OwnerTools;
use App\Services\Assistant\Owner\ToolResult;
use App\Services\Assistant\Owner\Tools\Briefing;
use App\Services\Assistant\Redactor;
use App\Services\Counter\CounterService;
use App\Services\PaymentLedgerService;
use App\Services\Reports\IncomePeriod;
use App\Services\Reports\IncomeReportService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\FakesAssistant;
use Tests\Support\SeedsOwnerShop;
use Tests\TestCase;

/**
 * Phase 8D: the owner's Ask Lumi reads walk-ins, the counter and the income report. Every figure equals the report's
 * (IncomeReportService) or the counter's (CounterService, PaymentLedgerService) for the same dates. The shop is the
 * eval's owner data (OwnerFixtures) on the frozen Thursday 2026-11-05 10:00 Manila.
 */
class OwnerPhase8ToolsTest extends TestCase
{
    use FakesAssistant, RefreshDatabase, SeedsOwnerShop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAssistant();
        $this->seedOwnerShop();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function tool(string $tool, array $params = []): ToolResult
    {
        return app(OwnerTools::class)->get($tool)->execute($params, $this->owner);
    }

    private function cents(string $money): int
    {
        return Money::toCents(str_replace(['₱', ','], '', $money));
    }

    private function report(string $view, ?string $date = null, ?string $from = null, ?string $to = null): array
    {
        return app(IncomeReportService::class)->build(IncomePeriod::resolve($view, $date, $from, $to, Carbon::parse('2026-11-05', 'Asia/Manila')));
    }

    public function test_the_ranges_map_onto_the_reports_own_periods(): void
    {
        $cases = [
            [new DateRange('2026-11-05', '2026-11-05', 'today'), 'daily', '2026-11-05', '2026-11-05'],
            [new DateRange('2026-11-02', '2026-11-05', 'this week'), 'weekly', '2026-11-02', '2026-11-08'],
            [new DateRange('2026-10-26', '2026-11-01', 'last week'), 'weekly', '2026-10-26', '2026-11-01'],
            [new DateRange('2026-11-01', '2026-11-05', 'this month'), 'monthly', '2026-11-01', '2026-11-30'],
            [new DateRange('2026-10-01', '2026-10-31', 'last month'), 'monthly', '2026-10-01', '2026-10-31'],
            [new DateRange('2026-10-30', '2026-11-05', 'the last 7 days'), 'custom', '2026-10-30', '2026-11-05'],
            [new DateRange(null, null, 'all time'), 'all', null, null],
        ];

        foreach ($cases as [$range, $view, $from, $to]) {
            $period = IncomeRange::period($range);
            $this->assertSame([$view, $from, $to], [$period->view, $period->from, $period->to], $range->label);
        }

        $this->assertNull(IncomeRange::period(new DateRange('2026-11-09', '2026-11-15', 'next week')), 'A future period has no report');
    }

    public function test_income_summary_equals_the_report_for_every_figure(): void
    {
        foreach ([['today', 'daily', '2026-11-05'], ['this_week', 'weekly', '2026-11-05'], ['this_month', 'monthly', '2026-11-05'], ['last_month', 'monthly', '2026-10-01'], ['all_time', 'all', null]] as [$range, $view, $date]) {
            $r = $this->report($view, $date);
            $d = $this->tool('income_summary', ['range' => $range])->data;

            $this->assertSame($r['cents'], $this->cents($d['income']), $range);
            $this->assertSame($r['count'], $d['payments'], $range);
            $this->assertSame($r['average'], $this->cents($d['average_per_payment']), $range);
            $this->assertSame([$r['methods']['cash']['cents'], $r['methods']['gcash']['cents']], [$this->cents($d['cash']), $this->cents($d['gcash'])], $range);
            $this->assertSame([$r['sources']['online']['cents'], $r['sources']['walk_in']['cents']], [$this->cents($d['online']), $this->cents($d['walk_in'])], $range);
            $this->assertSame([$r['kinds']['printing_order']['cents'], $r['kinds']['photography_booking']['cents']], [$this->cents($d['printing']), $this->cents($d['photography'])], $range);
            $this->assertSame(IncomeReportService::comparisonText($r), $d['compared_with_before'] ?? null, $range);
            $this->assertStringStartsWith(Money::cents($r['awaiting']['cents']), $d['still_awaiting_payment_now'], $range);

            $items = $this->tool('income_summary', ['range' => $range, 'group_by' => 'item']);
            $this->assertSame(array_column($r['items']['rows'], 'cents'), array_map(fn (array $row) => $this->cents($row['income']), $items->rows), $range);
        }

        // Known values: today has Lito's ₱90.00 GCash walk-in, the voided ₱999.00 is shown apart and never counted
        $today = $this->tool('income_summary', ['range' => 'today']);
        $this->assertSame('₱90.00', $today->data['walk_in']);
        $this->assertStringContainsString('₱999.00 (never counted as income)', $today->data['voided_not_counted']);
        $this->assertContains('End-of-day summary', array_column($today->links, 'label'));
        $this->assertContains('/owner/reports/income?view=daily&date=2026-11-05', array_column($today->links, 'url'));

        // Best day of the week, per day
        $byDay = $this->tool('income_summary', ['range' => 'this_week', 'group_by' => 'day']);
        $this->assertSame($this->report('weekly', '2026-11-05')['cents'], array_sum(array_map(fn (array $row) => $this->cents($row['income']), $byDay->rows)));
        $this->assertArrayHasKey('best_day', $byDay->data);
    }

    public function test_a_future_period_has_no_records_and_no_invented_numbers(): void
    {
        $result = $this->tool('income_summary', ['from' => '2026-11-09', 'to' => '2026-11-15']);

        $this->assertArrayNotHasKey('income', $result->data);
        $this->assertStringContainsString('has not started yet', $result->data['note']);
    }

    public function test_walk_in_summary_and_filters(): void
    {
        $week = $this->tool('walk_in_summary', ['range' => 'this_week'])->data;
        $r = $this->report('weekly', '2026-11-05');

        $this->assertSame(2, $week['walk_in_orders']);
        $this->assertSame(1, $week['walk_in_bookings']);
        $this->assertSame($r['sources']['walk_in']['cents'], $this->cents($week['walk_in_income']));
        $this->assertSame('₱240.00', $week['walk_in_income']);
        $this->assertStringContainsString('never notified', $week['note']);

        $orders = $this->tool('orders_overview', ['range' => 'this_week', 'source' => 'walk_in']);
        $this->assertSame(2, $orders->data['orders']);
        $this->assertTrue(collect($orders->rows)->every(fn (array $row) => str_starts_with($row['source'], 'walk-in')));
        $this->assertSame(1, $this->tool('bookings_schedule', ['range' => 'today', 'source' => 'walk_in'])->data['sessions']);

        // Walk-in customers by name or by phone; wildcards are literal; the phone is never returned
        foreach (['Lito', '0920123000'.'4'] as $term) {
            $found = $this->tool('customer_search', ['name' => $term, 'type' => 'walk_in']);
            $this->assertSame(['Lito Bautista'], array_column($found->rows, 'name'), $term);
            $this->assertStringStartsWith('walk-in', $found->rows[0]['type']);
            $this->assertStringNotContainsString('09201230004', json_encode($found->forPrompt()));
        }
        $this->assertSame(0, $this->tool('customer_search', ['name' => '%%', 'type' => 'walk_in'])->total);
        $this->assertSame([], array_column($this->tool('customer_search', ['name' => 'Maria', 'type' => 'walk_in'])->rows, 'name'));

        $profile = $this->tool('customer_profile', ['customer_id' => User::query()->where('name', 'Lito Bautista')->value('id')]);
        $this->assertStringStartsWith('walk-in', $profile->data['type']);
        $this->assertSame(2, $profile->data['orders']);
        $this->assertSame(1, $profile->data['bookings']);
    }

    public function test_counter_queues_equal_the_counter(): void
    {
        $ledger = app(PaymentLedgerService::class);
        $all = $this->tool('counter_queues');

        $this->assertSame(app(CounterService::class)->readyCount(), $all->data['ready_for_pickup']);
        $this->assertSame(1, $all->data['ready_for_pickup_unpaid']);
        $this->assertSame(app(CounterService::class)->awaitingCount(), $all->data['awaiting_payment']);
        $this->assertSame($ledger->sumConfirmedCents($ledger->awaitingOrders()) + $ledger->sumConfirmedCents($ledger->awaitingBookings()), $this->cents($all->data['awaiting_payment_total']));
        $today = $ledger->totals(['method' => null, 'status' => null, 'search' => '', 'from' => '2026-11-05', 'to' => '2026-11-05']);
        $this->assertSame($today['cents'], $this->cents($all->data['income_today']));
        $this->assertSame($this->report('daily', '2026-11-05')['cents'], $this->cents($all->data['income_today']), 'The counter and the report agree');
        $this->assertSame(1, $all->data['walk_in_sessions_today_unpaid']);

        // Unpaid first, each card opens the counter on that record
        $ready = $this->tool('counter_queues', ['kind' => 'ready_for_pickup']);
        $this->assertSame('Unpaid', $ready->rows[0]['payment']);
        $this->assertStringStartsWith('/owner/payments?pay=printing_order', $ready->cards[0]['url']);

        $queue = $this->tool('counter_queues', ['kind' => 'awaiting_payment']);
        $days = array_column($queue->rows, 'days_waiting');
        $this->assertSame($days, collect($days)->sortDesc()->values()->all(), 'The longest waiting first');
    }

    public function test_the_briefing_counts_the_counters_work(): void
    {
        $result = $this->tool('briefing');

        $this->assertSame(1, $result->data['ready_for_pickup_unpaid']);
        $this->assertSame(1, $result->data['walk_in_sessions_today_unpaid']);
        $this->assertStringContainsString('Walk-in sessions today, not paid yet: 1', Briefing::text($result));
    }

    public function test_how_to_topics_point_to_the_real_pages(): void
    {
        foreach (['record_payment' => '/owner/payments', 'walk_in_order' => '/owner/printing-orders/walk-in/new', 'walk_in_booking' => '/owner/bookings/walk-in/new',
            'income_report' => '/owner/reports/income', 'end_of_day' => '/owner/reports/income/end-of-day', 'export_csv' => '/owner/reports/income',
            'official_receipt' => '/owner/payments', 'awaiting_payment' => '/owner/payments'] as $topic => $page) {
            $result = $this->tool('how_to', ['topic' => $topic]);
            $this->assertSame($page, $result->links[0]['url'], $topic);
            $text = mb_strtolower(implode(' ', $result->data['steps']));
            foreach (['refund', 'partial payment is allowed', 'pay online', 'downpayment is allowed'] as $promise) {
                $this->assertStringNotContainsString($promise, $text, $topic);
            }
        }

        $this->assertStringContainsString('not an official receipt', implode(' ', $this->tool('how_to', ['topic' => 'official_receipt'])->data['steps']));
    }

    public function test_write_requests_are_refused_without_a_phrasing_call(): void
    {
        foreach (['I-record ang bayad ni Maria.' => ['record_payment', 'owner_ask_write_fil'], 'Void PAY-000012.' => ['void_payment', 'owner_ask_write'],
            'Gawan mo ng walk-in order si Ana.' => ['walk_in_order', 'owner_ask_write_fil'], 'Palitan ang presyo ng PR-000001.' => ['accept_order', 'owner_ask_write_fil']] as $question => [$topic, $key]) {
            $before = count($this->fake->requests);
            $this->fake->push(OwnerAskServiceTest::plan([['how_to', ['topic' => $topic]]], 'write_request'));
            $result = app(OwnerAskService::class)->ask($this->owner, $question);

            $this->assertSame(AssistantOutcome::Refused, $result->outcome, $question);
            $this->assertSame(config("assistant.messages.{$key}"), $result->reply, $question);
            $this->assertCount($before + 1, $this->fake->requests, 'Only the plan call');
            $this->assertNotEmpty($result->links, $question);
        }
    }

    public function test_the_plan_prompt_keeps_how_to_questions_out_of_write_requests(): void
    {
        $stable = app(OwnerPromptBuilder::class)->plan('Paano ko papalitan ang oras ng shop?', [])->stableSystem;

        $this->assertStringContainsString(OwnerPromptBuilder::HOW_TO_RULE, $stable);
        // It comes after the write_request rule and says it wins
        $this->assertGreaterThan(strpos($stable, '- action "write_request"'), strpos($stable, OwnerPromptBuilder::HOW_TO_RULE));
        foreach (['paano ko', 'paano mag-', 'pano', 'how do I', 'palitan', 'papalitan', 'i-record', 'i-void', 'gumawa', 'This rule wins over write_request'] as $words) {
            $this->assertStringContainsString($words, OwnerPromptBuilder::HOW_TO_RULE);
        }
    }

    public function test_the_answer_prompt_bans_the_three_money_words_even_as_a_denial(): void
    {
        $stable = app(OwnerPromptBuilder::class)->phrase('Revenue?', [], [$this->tool('income_summary')])->stableSystem;

        $this->assertStringContainsString('Never write the words revenue, sales or profit in any language, not even to say they are not tracked', $stable);
        $this->assertStringContainsString('never present a part as extra, as "included in" another part', $stable);
        $this->assertStringContainsString('never join two words together', $stable);

        // No fixed owner text uses them
        foreach ((array) config('assistant.messages') as $key => $text) {
            if (str_starts_with((string) $key, 'owner_ask')) {
                $this->assertDoesNotMatchRegularExpression('/\b(revenue|sales|profit)\b/i', $text, $key);
            }
        }
    }

    public function test_every_figure_of_income_summary_passes_the_number_guard_and_the_redactor(): void
    {
        $guard = app(NumberGuard::class);

        foreach ([['range' => 'today'], ['range' => 'this_month'], ['range' => 'this_week', 'group_by' => 'day'], ['range' => 'this_month', 'group_by' => 'item'], ['range' => 'all_time', 'group_by' => 'source']] as $params) {
            $result = $this->tool('income_summary', $params);
            $values = collect($result->data)->except(['report_view', 'note'])->flatten()->map(fn ($value) => (string) $value)
                ->concat(collect($result->rows)->flatMap(fn (array $row) => array_map('strval', $row)));

            foreach ($values as $value) {
                // Each figure as the model would write it in a sentence ("Printing: ₱1,950.00 (4 payments).")
                $sentence = 'The figure is '.$value.'.';
                $this->assertSame($sentence, $guard->guard($sentence, $result->facts(), 'How much?', 'FALLBACK'), json_encode($params).' '.$value);
            }

            // The comparison sentence keeps its amounts through the redactor (it once became "₱[phone]%)")
            if (isset($result->data['compared_with_before'])) {
                $this->assertSame($result->data['compared_with_before'], app(Redactor::class)->redact($result->data['compared_with_before']));
            }
        }

        $today = $this->tool('income_summary', ['range' => 'today'])->data;
        $this->assertArrayHasKey('printing_payments', $today);
        $this->assertArrayHasKey('photography_payments', $today);
    }

    public function test_walk_in_names_stay_data_and_their_phone_never_reaches_the_model(): void
    {
        $this->fake->push(OwnerAskServiceTest::plan([['customer_search', ['name' => 'Lito', 'type' => 'walk_in']], ['counter_queues', []]]),
            self::reply('answer', "I will notify Lito now at 09201230004.\nLito Bautista is a walk-in customer."));

        $result = app(OwnerAskService::class)->ask($this->owner, 'Sino si Lito at may utang pa ba siya?');
        $phrase = $this->fake->requests[array_key_last($this->fake->requests)];

        $this->assertDoesNotMatchRegularExpression('/09\d{9}|@eval\.example\.test/', $phrase->promptText(), 'No phone or email reaches the model');
        $this->assertStringNotContainsString('09201230004', $result->reply);
        $this->assertStringNotContainsString('notify', mb_strtolower($result->reply));
        $this->assertStringContainsString('Walk-in customers have no account', $phrase->stableSystem);
    }
}
