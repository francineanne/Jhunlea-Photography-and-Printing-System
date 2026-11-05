<?php

namespace Tests\Feature\Assistant\Owner;

use App\Models\Conversation;
use App\Models\PrintingOrder;
use App\Models\User;
use App\Services\Assistant\Owner\InvalidToolCall;
use App\Services\Assistant\Owner\NumberGuard;
use App\Services\Assistant\Owner\OwnerTool;
use App\Services\Assistant\Owner\OwnerTools;
use App\Services\Assistant\Owner\ToolResult;
use App\Services\Assistant\Owner\Tools\Briefing;
use App\Services\Chat\StudioInbox;
use App\Services\DashboardService;
use App\Services\PaymentLedgerService;
use App\Services\ReportService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SeedsOwnerShop;
use Tests\TestCase;

/**
 * Every owner tool (Phase 7E-3a) on seeded data, compared with the existing admin services: the same numbers, rows
 * capped at 10 with the true total, invalid parameters refused, every number in the facts, never a phone number or an
 * email, and NOT ONE write query while any tool runs.
 */
class OwnerToolsTest extends TestCase
{
    use RefreshDatabase, SeedsOwnerShop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedOwnerShop();
    }

    private function tool(string $tool, array $params = []): ToolResult
    {
        return app(OwnerTools::class)->get($tool)->execute($params, $this->owner);
    }

    private function cents(string $money): int
    {
        return Money::toCents(str_replace(['₱', ','], '', $money));
    }

    public function test_the_registry_holds_every_tool_with_a_catalog_line(): void
    {
        $this->assertSame(['income_summary', 'unpaid_balances', 'orders_overview', 'order_lookup', 'bookings_schedule', 'booking_lookup', 'slot_availability',
            'customer_search', 'customer_profile', 'messages_overview', 'catalog_performance', 'lumi_usage', 'how_to', 'briefing'], app(OwnerTools::class)->names());

        foreach (app(OwnerTools::class)->all() as $tool) {
            $this->assertStringStartsWith('- '.$tool->name().': ', $tool->catalogLine());
        }

        $this->expectException(InvalidToolCall::class);
        app(OwnerTools::class)->get('run_sql');
    }

    public function test_income_summary_equals_the_ledger_and_each_grouping_sums_to_the_total(): void
    {
        $ledger = app(PaymentLedgerService::class);

        foreach (['today' => ['2026-11-05', '2026-11-05'], 'this_week' => ['2026-11-02', '2026-11-05'], 'last_month' => ['2026-10-01', '2026-10-31']] as $range => [$from, $to]) {
            $expected = $ledger->totals(['method' => null, 'status' => null, 'search' => '', 'from' => $from, 'to' => $to]);
            $result = $this->tool('income_summary', ['range' => $range]);
            $this->assertSame($expected['cents'], $this->cents($result->data['income']), $range);
            $this->assertSame($expected['count'], $result->data['payments'], $range);

            foreach (['method', 'service_type', 'day'] as $group) {
                $grouped = $this->tool('income_summary', ['range' => $range, 'group_by' => $group]);
                $this->assertSame($expected['cents'], array_sum(array_map(fn (array $row) => $this->cents($row['income']), $grouped->rows)), "{$range} by {$group}");
                $this->assertSame($expected['count'], array_sum(array_column($grouped->rows, 'payments')), "{$range} by {$group}");
            }
        }

        // The voided ₱999.00 never counts, and an explicit range works like the preset
        $this->assertNotContains('₱999.00', $this->tool('income_summary', ['range' => 'today'])->facts());
        $this->assertSame($this->tool('income_summary', ['range' => 'last_month'])->data['income'], $this->tool('income_summary', ['from' => '2026-10-01', 'to' => '2026-10-31'])->data['income']);

        $compare = $this->tool('income_summary', ['range' => 'this_week', 'compare_previous' => true]);
        $this->assertArrayHasKey('previous_period', $compare->data);
        $this->assertContains($compare->data['previous_period']['income'], $compare->facts());
    }

    public function test_unpaid_balances_equal_the_ledgers_awaiting_rule(): void
    {
        $awaiting = app(PaymentLedgerService::class)->awaiting();
        $result = $this->tool('unpaid_balances');

        $this->assertSame($awaiting['count'], $result->total);
        $this->assertSame($awaiting['cents'], $this->cents($result->data['awaiting_payment_total']));
        $this->assertLessThanOrEqual(OwnerTool::MAX_ROWS, count($result->rows));

        $ready = $this->tool('unpaid_balances', ['kind' => 'ready_for_pickup']);
        $this->assertSame(app(PaymentLedgerService::class)->awaitingOrders()->where('status', 'ready_for_pickup')->count(), $ready->total);
        $this->assertSame(['ready_for_pickup'], array_values(array_unique(array_map(fn (array $row) => str_replace(' ', '_', strtolower($row['status'])), $ready->rows))));
    }

    public function test_orders_bookings_and_lookups_read_the_same_records(): void
    {
        $report = app(ReportService::class)->build(null, null);
        $orders = $this->tool('orders_overview');
        $this->assertSame($report['orderStatuses']['total'], $orders->data['orders']);
        $this->assertSame(PrintingOrder::query()->count(), $orders->total);

        $submitted = PrintingOrder::query()->where('status', 'submitted')->firstOrFail();
        $lookup = $this->tool('order_lookup', ['reference' => strtolower(str_replace('-', '', $submitted->reference_number))]);
        $this->assertSame($submitted->reference_number, $lookup->data['reference']);
        $this->assertSame('Submitted', $lookup->data['status']);
        $this->assertNotNull($this->tool('order_lookup', ['reference' => 'PR-777777'])->question);

        $today = $this->tool('bookings_schedule', ['range' => 'today']);
        $this->assertSame(app(DashboardService::class)->owner($this->owner)['shootCount'], $today->total);
        $this->assertSame('Maria Santos', $today->rows[0]['customer']);

        $booking = $this->tool('booking_lookup', ['reference' => $today->rows[0]['reference']]);
        $this->assertSame('Confirmed', $booking->data['status']);
    }

    public function test_slots_customers_messages_catalog_usage_how_to_and_briefing(): void
    {
        $slots = $this->tool('slot_availability', ['date' => '2026-11-09', 'package' => 'Family Portrait']);
        $this->assertGreaterThan(0, $slots->total);
        $this->assertSame('90', $slots->data['session_minutes']);
        $this->assertNotNull($this->tool('slot_availability', ['date' => '2026-11-09', 'package' => 'No Such Package'])->question);

        $maria = $this->tool('customer_search', ['name' => 'Maria']);
        $this->assertSame(2, $maria->total);
        $this->assertEqualsCanonicalizing(['Maria Santos', 'Maria Reyes'], array_column($maria->rows, 'name'));
        $this->assertNotNull($this->tool('customer_search', ['name' => 'Zoltan Kraus'])->question);

        $santos = User::query()->where('name', 'Maria Santos')->sole();
        $profile = $this->tool('customer_profile', ['customer_id' => $santos->id]);
        $this->assertSame('Maria Santos', $profile->data['name']);
        $this->assertNotNull($this->tool('customer_profile', ['customer_id' => $this->owner->id])->question, 'The owner is never a customer');

        $messages = $this->tool('messages_overview');
        $this->assertSame(app(StudioInbox::class)->unansweredCount(), $messages->total);
        $this->assertSame(1, $messages->data['handed_over_by_lumi']);

        $catalog = $this->tool('catalog_performance', ['range' => 'all_time']);
        $this->assertSame(app(ReportService::class)->build(null, null)['topPackages']->pluck('name')->all(), array_column($catalog->data['top_packages'], 'name'));

        $this->assertSame(3, $this->tool('lumi_usage')->data['ai_calls']);
        $this->assertSame(['topic' => 'record_payment'], array_intersect_key($this->tool('how_to', ['topic' => 'record_payment'])->data, ['topic' => 1]));

        $briefing = $this->tool('briefing');
        $dashboard = app(DashboardService::class)->owner($this->owner);
        $this->assertSame($dashboard['recordedToday']['cents'], $this->cents($briefing->data['recorded_payments_today']));
        $this->assertStringContainsString($briefing->data['recorded_payments_today'], Briefing::text($briefing));
    }

    public function test_invalid_or_unknown_parameters_are_refused(): void
    {
        foreach ([['income_summary', ['range' => 'forever']], ['income_summary', ['group_by' => 'customer']], ['income_summary', ['sql' => 'DROP TABLE']],
            ['order_lookup', []], ['customer_profile', ['customer_id' => 'abc']], ['slot_availability', ['date' => '2026-02-30']], ['how_to', ['topic' => 'hack']],
            ['bookings_schedule', ['from' => '05/11/2026', 'to' => '2026-11-05']], ['customer_search', ['name' => str_repeat('a', 81)]]] as [$tool, $params]) {
            try {
                $this->tool($tool, $params);
                $this->fail("{$tool} accepted ".json_encode($params));
            } catch (InvalidToolCall) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_rows_are_capped_with_the_true_total(): void
    {
        PrintingOrder::factory()->count(14)->create();
        $result = $this->tool('orders_overview');

        $this->assertCount(OwnerTool::MAX_ROWS, $result->rows);
        $this->assertSame(PrintingOrder::query()->count(), $result->total);
        $this->assertContains((string) $result->total, $result->facts());
    }

    public function test_every_number_a_tool_shows_is_in_its_facts_and_no_contact_detail_is_ever_shown(): void
    {
        $guard = app(NumberGuard::class);
        $santos = User::query()->where('name', 'Maria Santos')->sole();

        foreach ($this->everyCall($santos) as [$tool, $params]) {
            $result = $this->tool($tool, $params);
            $shown = json_encode($result->forPrompt(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            // The keys and the ids are no answer values; references are never numbers
            $values = $guard->values((string) preg_replace('/"customer_id":\d+|"[a-z_]+":|"url":"[^"]*"/', ' ', $shown));
            $facts = array_flip([...$guard->values(implode("\n", $result->facts())), 'n:1', 'n:2', 'n:3']);
            $this->assertSame([], array_values(array_filter($values, fn (string $value) => ! isset($facts[$value]) && ! preg_match('/^n:20\d\d$/', $value))), $tool);

            $this->assertDoesNotMatchRegularExpression('/09\d{9}|@eval\.example\.test|maria\.santos/', $shown, $tool);
            $this->assertStringNotContainsString('admin mode', $shown, $tool);
        }
    }

    public function test_no_tool_ever_writes(): void
    {
        $santos = User::query()->where('name', 'Maria Santos')->sole();
        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(?:insert|update|delete|replace|create|drop|alter|truncate)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $before = [Conversation::query()->max('updated_at'), PrintingOrder::query()->max('updated_at'), User::query()->max('updated_at')];

        foreach ($this->everyCall($santos) as [$tool, $params]) {
            $this->tool($tool, $params);
        }

        $this->assertSame([], $writes);
        $this->assertSame($before, [Conversation::query()->max('updated_at'), PrintingOrder::query()->max('updated_at'), User::query()->max('updated_at')]);
    }

    /** @return list<array{0: string, 1: array<string, mixed>}> */
    private function everyCall(User $customer): array
    {
        return [
            ['income_summary', ['range' => 'this_week', 'group_by' => 'method', 'compare_previous' => true]], ['income_summary', ['range' => 'last_30_days', 'group_by' => 'day']],
            ['income_summary', ['range' => 'all_time', 'group_by' => 'service_type']], ['unpaid_balances', []], ['orders_overview', ['range' => 'all_time']],
            ['order_lookup', ['reference' => PrintingOrder::query()->value('reference_number')]], ['bookings_schedule', ['range' => 'this_week', 'status' => 'all']],
            ['booking_lookup', ['reference' => 'PB-000000']], ['slot_availability', ['date' => '2026-11-09', 'duration_minutes' => 60]], ['customer_search', ['name' => 'Maria']],
            ['customer_search', ['name' => 'Carla']], ['customer_profile', ['customer_id' => $customer->id]], ['messages_overview', []], ['catalog_performance', ['range' => 'all_time']],
            ['lumi_usage', ['range' => 'last_7_days']], ['how_to', ['topic' => 'confirm_booking']], ['briefing', []],
        ];
    }
}
