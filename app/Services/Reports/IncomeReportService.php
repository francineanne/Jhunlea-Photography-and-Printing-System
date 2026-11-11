<?php

namespace App\Services\Reports;

use App\Enums\PaymentRecordStatus;
use App\Enums\RecordSource;
use App\Models\Payment;
use App\Models\PhotographyPackage;
use App\Models\PrintingService;
use App\Services\PaymentLedgerService;
use App\Support\LocalTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The shop's income for a period (Phase 8C), read only. Income is exactly the ledger's own total: RECORDED payments
 * received in the period's Manila days (PaymentLedgerService::totals() and recorded()), so the report and the ledger
 * can never drift apart. Voided payments are counted apart and never added in; estimates and "awaiting payment" are
 * never income. Every figure is grouped by the database: a fixed number of queries, however many payments exist.
 */
class IncomeReportService
{
    public const TOP = 10;

    public function __construct(private readonly PaymentLedgerService $ledger) {}

    /** @return array<string, mixed> */
    public function build(IncomePeriod $period): array
    {
        $totals = $this->ledger->totals($period->filters());
        $recorded = fn () => $this->ledger->recorded($period->from, $period->to);
        $voided = $this->ledger->inPeriod($period->from, $period->to)->where('status', PaymentRecordStatus::Voided)
            ->toBase()->selectRaw('COUNT(*) as total, SUM(ROUND(amount * 100)) as cents')->first();
        $awaitingOrders = $this->ledger->awaitingOrders();
        $awaitingBookings = $this->ledger->awaitingBookings();

        return [
            'cents' => $totals['cents'],
            'count' => $totals['count'],
            'average' => $totals['count'] > 0 ? (int) round($totals['cents'] / $totals['count']) : 0,
            'methods' => $totals['methods'],
            'sources' => $this->bySource($recorded()),
            'kinds' => $this->byKind($recorded()),
            'items' => $this->byItem($recorded()),
            'series' => $this->series($recorded(), $period),
            'voided' => ['count' => (int) ($voided->total ?? 0), 'cents' => (int) ($voided->cents ?? 0)],
            'awaiting' => [
                'count' => (clone $awaitingOrders)->count() + (clone $awaitingBookings)->count(),
                'cents' => $this->ledger->sumConfirmedCents($awaitingOrders) + $this->ledger->sumConfirmedCents($awaitingBookings),
            ],
            'comparison' => $this->compare($totals['cents'], $period->previous),
        ];
    }

    /**
     * The payments of one day for the printable end-of-day summary: recorded and voided apart, the oldest first.
     *
     * @return array{recorded: Collection<int, Payment>, voided: Collection<int, Payment>}
     */
    public function dayPayments(string $date): array
    {
        $rows = $this->ledger->listing(['method' => null, 'status' => null, 'search' => '', 'from' => $date, 'to' => $date])
            ->oldest('received_at')->oldest('id')->get();

        return [
            'recorded' => $rows->filter(fn (Payment $payment) => $payment->isRecorded())->values(),
            'voided' => $rows->reject(fn (Payment $payment) => $payment->isRecorded())->values(),
        ];
    }

    /**
     * The previous period's income and the change, in words that never divide by zero.
     *
     * @param  array{from: string, to: string, label: string}|null  $previous
     * @return array{label: string, cents: int, count: int, change: int, percent: ?float, direction: string}|null
     */
    private function compare(int $cents, ?array $previous): ?array
    {
        if ($previous === null) {
            return null;
        }

        $before = $this->ledger->totals(['method' => null, 'status' => null, 'search' => '', 'from' => $previous['from'], 'to' => $previous['to']]);
        $change = $cents - $before['cents'];

        return [
            'label' => $previous['label'],
            'cents' => $before['cents'],
            'count' => $before['count'],
            'change' => $change,
            'percent' => $before['cents'] > 0 ? round($change / $before['cents'] * 100, 1) : null,
            'direction' => $change > 0 ? 'up' : ($change < 0 ? 'down' : 'same'),
        ];
    }

    /**
     * Online and walk-in income, by the paid order's or booking's own `source` (removed records included).
     *
     * @return array<string, array{count: int, cents: int}>
     */
    private function bySource(Builder $recorded): array
    {
        $source = "CASE WHEN payable_type = 'printing_order'"
            .' THEN (SELECT o.source FROM printing_orders o WHERE o.id = payments.payable_id)'
            .' ELSE (SELECT b.source FROM photography_bookings b WHERE b.id = payments.payable_id) END';
        $rows = $this->grouped($recorded, "{$source} as src", ['src'])->keyBy('src');

        return collect(RecordSource::cases())->mapWithKeys(fn (RecordSource $item) => [$item->value => [
            'count' => (int) ($rows[$item->value]->total ?? 0),
            'cents' => (int) ($rows[$item->value]->cents ?? 0),
        ]])->all();
    }

    /** @return array{printing_order: array{count: int, cents: int}, photography_booking: array{count: int, cents: int}} */
    private function byKind(Builder $recorded): array
    {
        $rows = $recorded->toBase()->selectRaw('payable_type, COUNT(*) as total, SUM(ROUND(amount * 100)) as cents')->groupBy('payable_type')->get()->keyBy('payable_type');

        return collect(['printing_order', 'photography_booking'])->mapWithKeys(fn (string $kind) => [$kind => [
            'count' => (int) ($rows[$kind]->total ?? 0),
            'cents' => (int) ($rows[$kind]->cents ?? 0),
        ]])->all();
    }

    /**
     * Income per printing service and per photography package, the highest first (ties by kind and id, the same order
     * every time): the top TOP, then the rest as one "Other" row. Names come from the catalog, removed items included.
     *
     * @return array{rows: list<array{kind: string, name: string, count: int, cents: int}>, other: array{items: int, count: int, cents: int}}
     */
    private function byItem(Builder $recorded): array
    {
        $item = "CASE WHEN payable_type = 'printing_order'"
            .' THEN (SELECT o.printing_service_id FROM printing_orders o WHERE o.id = payments.payable_id)'
            .' ELSE (SELECT b.photography_package_id FROM photography_bookings b WHERE b.id = payments.payable_id) END';
        $groups = $this->grouped($recorded, "payable_type as kind, {$item} as item", ['kind', 'item'])
            ->sortBy([['cents', 'desc'], ['kind', 'desc'], ['item', 'asc']])
            ->values();

        $top = $groups->take(self::TOP);
        $services = PrintingService::withTrashed()->whereKey($top->where('kind', 'printing_order')->pluck('item')->filter()->all())->pluck('name', 'id');
        $packages = PhotographyPackage::withTrashed()->whereKey($top->where('kind', 'photography_booking')->pluck('item')->filter()->all())->pluck('name', 'id');
        $rest = $groups->slice(self::TOP);

        return [
            'rows' => $top->map(fn ($group) => [
                'kind' => $group->kind,
                'name' => (string) ($group->kind === 'printing_order' ? ($services[$group->item] ?? 'Printing service') : ($packages[$group->item] ?? 'Photography package')),
                'count' => (int) $group->total,
                'cents' => (int) $group->cents,
            ])->all(),
            'other' => ['items' => $rest->count(), 'count' => (int) $rest->sum('total'), 'cents' => (int) $rest->sum('cents')],
        ];
    }

    /**
     * Income over time inside the period: per hour for a day, per day for a week, a month or a short custom range, per
     * month for All time and long ranges. Grouped in the database on the shop's local time (Manila is UTC+8 all year,
     * no daylight saving), every slot listed, zeros included.
     *
     * @return list<array{key: string, label: string, short: string, count: int, cents: int}>
     */
    private function series(Builder $recorded, IncomePeriod $period): array
    {
        $format = ['hour' => '%H', 'day' => '%Y-%m-%d', 'month' => '%Y-%m'][$period->bucket];
        $expression = $this->localFormat($format, $period->from ?? Carbon::now('UTC')->format('Y-m-d'));
        $rows = $this->grouped($recorded, "{$expression} as slot", ['slot'])->keyBy('slot');

        $slots = $this->slots($period, $rows->keys());

        return array_map(fn (array $slot) => $slot + [
            'count' => (int) ($rows[$slot['key']]->total ?? 0),
            'cents' => (int) ($rows[$slot['key']]->cents ?? 0),
        ], $slots);
    }

    /**
     * Count and sum the recorded payments by worked-out columns: the inner query works them out per payment, the outer
     * one groups by their plain names. MySQL's ONLY_FULL_GROUP_BY refuses to group by an expression that holds a
     * subquery, so the grouping never repeats the expression (works the same in SQLite).
     *
     * @param  list<string>  $keys
     * @return Collection<int, object>
     */
    private function grouped(Builder $recorded, string $columns, array $keys): Collection
    {
        return DB::query()
            ->fromSub($recorded->toBase()->selectRaw("{$columns}, amount"), 'paid')
            ->select($keys)
            ->selectRaw('COUNT(*) as total, SUM(ROUND(amount * 100)) as cents')
            ->groupBy($keys)
            ->get();
    }

    /**
     * Every slot of the chart. A day shows the shop's usual hours (8 AM to 8 PM) widened to any hour with a payment;
     * All time runs from the first month with a payment to this month.
     *
     * @param  Collection<int, string>  $found
     * @return list<array{key: string, label: string, short: string}>
     */
    private function slots(IncomePeriod $period, Collection $found): array
    {
        $today = LocalTime::in(Carbon::now('UTC'))->startOfDay();
        $slots = [];

        if ($period->bucket === 'hour') {
            $hours = $found->map(fn ($hour) => (int) $hour);
            foreach (range(min(8, $hours->min() ?? 8), max(20, $hours->max() ?? 20)) as $hour) {
                $time = Carbon::createFromTime($hour);
                $slots[] = ['key' => sprintf('%02d', $hour), 'label' => $time->format('g A').' to '.$time->copy()->addHour()->format('g A'), 'short' => $time->format('gA')];
            }

            return $slots;
        }

        if ($period->bucket === 'day') {
            $day = Carbon::parse($period->from, $today->getTimezone());
            $end = Carbon::parse($period->to, $today->getTimezone());
            for (; $day->lessThanOrEqualTo($end); $day->addDay()) {
                $slots[] = ['key' => $day->format('Y-m-d'), 'label' => $day->format('D, M j'), 'short' => $period->view === 'weekly' ? $day->format('D j') : $day->format('j')];
            }

            return $slots;
        }

        $first = $period->from !== null ? Carbon::parse($period->from, $today->getTimezone()) : ($found->isEmpty() ? $today->copy() : Carbon::parse($found->min().'-01', $today->getTimezone()));
        $last = $period->to !== null ? Carbon::parse($period->to, $today->getTimezone()) : $today->copy();
        for ($month = $first->copy()->startOfMonth(); $month->lessThanOrEqualTo($last); $month->addMonthNoOverflow()) {
            $slots[] = ['key' => $month->format('Y-m'), 'label' => $month->format('F Y'), 'short' => $month->format('M y')];
        }

        return $slots;
    }

    /** SQL that formats the stored UTC `received_at` in the shop's local time, for MySQL and SQLite. */
    private function localFormat(string $format, string $onDay): string
    {
        $minutes = (int) Carbon::parse($onDay, config('app.display_timezone'))->utcOffset();

        return DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('{$format}', received_at, '{$this->signed($minutes)} minutes')"
            : "DATE_FORMAT(DATE_ADD(received_at, INTERVAL {$minutes} MINUTE), '{$format}')";
    }

    private function signed(int $minutes): string
    {
        return ($minutes >= 0 ? '+' : '').$minutes;
    }
}
