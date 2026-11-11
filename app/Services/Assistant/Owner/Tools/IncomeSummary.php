<?php

namespace App\Services\Assistant\Owner\Tools;

use App\Models\User;
use App\Services\Assistant\Owner\IncomeRange;
use App\Services\Assistant\Owner\OwnerTool;
use App\Services\Assistant\Owner\ToolResult;
use App\Services\Reports\IncomeReportService;

/**
 * The shop's income for a period, from the income report itself (Phase 8D: IncomeReportService::build() on the same
 * period as the Reports page, through IncomeRange), so every figure equals the report's: income (recorded payments
 * only; voided never count, estimates and unpaid prices never), average, Cash and GCash, Online and Walk-in, Printing
 * and Photography, the top services and packages, income per day or hour, voided payments apart, what is still
 * awaiting payment right now, and the comparison with the period before in the report's own words.
 */
class IncomeSummary extends OwnerTool
{
    public function __construct(private readonly IncomeReportService $reports) {}

    public function name(): string
    {
        return 'income_summary';
    }

    public function description(): string
    {
        return 'Income (recorded payments) in a period, exactly as the Income report shows it: total, payments, average, Cash and GCash, Online and Walk-in, Printing and Photography, voided payments (never counted), still awaiting payment, and the comparison with the period before. group_by adds rows: method, service_type, source (online or walk-in), item (top services and packages), day (per day, or per hour for one day; also the best one).';
    }

    public function parameters(): array
    {
        return self::rangeParameters() + [
            'group_by' => ['type' => 'string', 'enum' => ['none', 'method', 'service_type', 'source', 'item', 'day'], 'default' => 'none'],
            'compare_previous' => ['type' => 'boolean', 'default' => true, 'description' => 'the comparison is always included'],
        ];
    }

    protected function handle(array $params, User $owner): ToolResult
    {
        $range = $this->range($params);
        $result = new ToolResult($this->name());
        $period = IncomeRange::period($range);

        if ($period === null) {
            $result->data = ['period' => $result->label($range->label), 'from' => $result->day($range->from), 'to' => $result->day($range->to),
                'note' => 'This period has not started yet, so there are no records for it.'];

            return $result;
        }

        $r = $this->reports->build($period);
        $result->data = self::facts($r, $result) + [
            'period' => $result->label($range->label),
            'report_view' => $period->view,
            'from' => $result->day($period->from),
            'to' => $result->day($period->to),
        ];

        $rows = match ($params['group_by']) {
            'method' => [['group' => 'Cash'] + $r['methods']['cash'], ['group' => 'GCash'] + $r['methods']['gcash']],
            'service_type' => [['group' => 'Printing'] + $r['kinds']['printing_order'], ['group' => 'Photography'] + $r['kinds']['photography_booking']],
            'source' => [['group' => 'Online'] + $r['sources']['online'], ['group' => 'Walk-in'] + $r['sources']['walk_in']],
            'item' => array_map(fn (array $item) => ['group' => $item['name'].' ('.($item['kind'] === 'printing_order' ? 'printing' : 'photography').')', 'count' => $item['count'], 'cents' => $item['cents']], $r['items']['rows']),
            'day' => array_values(array_filter(array_map(fn (array $slot) => ['group' => $slot['label'], 'count' => $slot['count'], 'cents' => $slot['cents']], $r['series']), fn (array $row) => $row['count'] > 0)),
            default => [],
        };

        foreach ($rows as $row) {
            $result->rows[] = ['group' => $result->label($row['group']), 'income' => $result->money($row['cents']), 'payments' => $result->count($row['count'])];
        }

        if ($params['group_by'] === 'item' && $r['items']['other']['items'] > 0) {
            $result->data['other_items'] = ['items' => $result->count($r['items']['other']['items']), 'income' => $result->money($r['items']['other']['cents']), 'payments' => $result->count($r['items']['other']['count'])];
        }

        if ($params['group_by'] === 'day' && $rows !== []) {
            $best = collect($rows)->sortByDesc('cents')->first();
            $result->data[$period->bucket === 'hour' ? 'best_hour' : ($period->bucket === 'month' ? 'best_month' : 'best_day')] = $result->label($best['group']).': '.$result->money($best['cents']);
        }

        if ($params['group_by'] !== 'none') {
            $result->total = count($result->rows);
            $result->rows = array_slice($result->rows, 0, 31);
        }

        $result->link('Income report', route('owner.reports.income', IncomeRange::reportQuery($period)));
        if ($period->view === 'daily') {
            $result->link('End-of-day summary', route('owner.reports.end-of-day', ['date' => $period->date]));
        }
        $result->link('Payments ledger', route('owner.payments.index', $period->ledgerQuery()));

        return $result;
    }

    /**
     * The report's figures as tool facts (every number through the ToolResult helpers).
     *
     * @param  array<string, mixed>  $r  an IncomeReportService::build() result
     * @return array<string, mixed>
     */
    public static function facts(array $r, ToolResult $result): array
    {
        return array_filter([
            'income' => $result->money($r['cents']),
            'payments' => $result->count($r['count']),
            'average_per_payment' => $result->money($r['average']),
            'cash' => $result->money($r['methods']['cash']['cents']),
            'cash_payments' => $result->count($r['methods']['cash']['count']),
            'gcash' => $result->money($r['methods']['gcash']['cents']),
            'gcash_payments' => $result->count($r['methods']['gcash']['count']),
            'online' => $result->money($r['sources']['online']['cents']),
            'online_payments' => $result->count($r['sources']['online']['count']),
            'walk_in' => $result->money($r['sources']['walk_in']['cents']),
            'walk_in_payments' => $result->count($r['sources']['walk_in']['count']),
            'printing' => $result->money($r['kinds']['printing_order']['cents']),
            // 8D fix: the counts too, so "Printing: ₱X (4 payments)" is a fact and not removed by NumberGuard
            'printing_payments' => $result->count($r['kinds']['printing_order']['count']),
            'photography' => $result->money($r['kinds']['photography_booking']['cents']),
            'photography_payments' => $result->count($r['kinds']['photography_booking']['count']),
            'voided_not_counted' => $r['voided']['count'] > 0 ? $result->count($r['voided']['count']).' voided, '.$result->money($r['voided']['cents']).' (never counted as income)' : null,
            'still_awaiting_payment_now' => $result->money($r['awaiting']['cents']).' from '.$result->count($r['awaiting']['count']).' orders or bookings (right now, any date; not income)',
            'compared_with_before' => self::comparison($r, $result),
        ], fn ($value) => $value !== null);
    }

    /** The report's comparison sentence, with its amounts and percentage registered as facts. @param array<string, mixed> $r */
    private static function comparison(array $r, ToolResult $result): ?string
    {
        if (($cmp = $r['comparison']) === null) {
            return null;
        }

        $result->label($cmp['label']);
        $result->money($cmp['cents']);
        $result->money(abs($cmp['change']));
        if ($cmp['percent'] !== null) {
            $result->percent(abs($cmp['percent']));
        }

        return IncomeReportService::comparisonText($r);
    }
}
