<?php

namespace App\Services\Assistant\Owner\Tools;

use App\Models\User;
use App\Services\Assistant\Owner\DateRange;
use App\Services\Assistant\Owner\OwnerTool;
use App\Services\Assistant\Owner\ToolResult;
use App\Services\PaymentLedgerService;
use App\Support\LocalTime;
use App\Support\Money;

/**
 * Recorded payments received in a period (the ledger's rule: voided never count, estimates and unpaid prices never),
 * in all, or by method, by kind (printing or photography) or by day; optionally against the period right before.
 */
class IncomeSummary extends OwnerTool
{
    public function __construct(private readonly PaymentLedgerService $ledger) {}

    public function name(): string
    {
        return 'income_summary';
    }

    public function description(): string
    {
        return 'Recorded payments (income) received in a period: total and count, optionally grouped, and compared with the period before. Voided payments never count.';
    }

    public function parameters(): array
    {
        return self::rangeParameters() + [
            'group_by' => ['type' => 'string', 'enum' => ['none', 'method', 'service_type', 'day'], 'default' => 'none'],
            'compare_previous' => ['type' => 'boolean', 'default' => false, 'description' => 'also the period of the same length right before'],
        ];
    }

    protected function handle(array $params, User $owner): ToolResult
    {
        $range = $this->range($params);
        $result = new ToolResult($this->name());
        $totals = $this->ledger->totals(['method' => null, 'status' => null, 'search' => '', 'from' => $range->from, 'to' => $range->to]);

        $result->data = [
            'period' => $result->label($range->label),
            'from' => $result->day($range->from),
            'to' => $result->day($range->to),
            'income' => $result->money($totals['cents']),
            'payments' => $result->count($totals['count']),
        ];

        $groups = match ($params['group_by']) {
            'method' => array_map(fn (string $method, array $row) => ['group' => $method === 'gcash' ? 'GCash' : 'Cash', 'cents' => $row['cents'], 'count' => $row['count']],
                array_keys($totals['methods']), $totals['methods']),
            'service_type' => $this->byKind($range),
            'day' => $this->byDay($range),
            default => [],
        };

        foreach ($groups as $group) {
            $result->rows[] = ['group' => $params['group_by'] === 'day' ? $result->day($group['group']) : $group['group'], 'income' => $result->money($group['cents']), 'payments' => $result->count($group['count'])];
        }

        if ($params['group_by'] !== 'none') {
            $result->total = count($result->rows);
            $result->rows = array_slice($result->rows, 0, $params['group_by'] === 'day' ? 31 : self::MAX_ROWS);
        }

        if ($params['compare_previous'] && ($previous = $range->previous()) !== null) {
            $before = $this->ledger->totals(['method' => null, 'status' => null, 'search' => '', 'from' => $previous->from, 'to' => $previous->to]);
            $result->data['previous_period'] = ['from' => $result->day($previous->from), 'to' => $result->day($previous->to),
                'income' => $result->money($before['cents']), 'payments' => $result->count($before['count'])];
            $difference = $totals['cents'] - $before['cents'];
            $result->data['difference'] = ($difference < 0 ? 'less by ' : 'more by ').$result->money(abs($difference));
            $result->data['change_percent'] = $before['cents'] === 0 ? 'no income in the period before' : $result->percent($difference / $before['cents'] * 100);
        }

        $result->link('Payments ledger', route('owner.payments.index', array_filter(['from' => $range->from, 'to' => $range->to])));
        $result->link('Reports', route('owner.reports.index'));

        return $result;
    }

    /** @return list<array{group: string, cents: int, count: int}> */
    private function byKind(DateRange $range): array
    {
        $rows = $this->ledger->recorded($range->from, $range->to)->toBase()
            ->selectRaw('payable_type, COUNT(*) as total, SUM(ROUND(amount * 100)) as cents')->groupBy('payable_type')->get()->keyBy('payable_type');

        return [
            ['group' => 'Printing orders', 'cents' => (int) ($rows['printing_order']->cents ?? 0), 'count' => (int) ($rows['printing_order']->total ?? 0)],
            ['group' => 'Photography bookings', 'cents' => (int) ($rows['photography_booking']->cents ?? 0), 'count' => (int) ($rows['photography_booking']->total ?? 0)],
        ];
    }

    /** By Manila day, in PHP (the stored moments are UTC), only days with a payment. @return list<array{group: string, cents: int, count: int}> */
    private function byDay(DateRange $range): array
    {
        $days = [];

        foreach ($this->ledger->recorded($range->from, $range->to)->toBase()->select(['received_at', 'amount'])->orderBy('received_at')->cursor() as $row) {
            $day = LocalTime::format($row->received_at, 'Y-m-d');
            $days[$day] ??= ['group' => $day, 'cents' => 0, 'count' => 0];
            $days[$day]['cents'] += Money::toCents($row->amount);
            $days[$day]['count']++;
        }

        return array_values($days);
    }
}
