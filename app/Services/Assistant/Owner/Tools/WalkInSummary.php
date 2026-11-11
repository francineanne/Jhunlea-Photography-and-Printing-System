<?php

namespace App\Services\Assistant\Owner\Tools;

use App\Enums\RecordSource;
use App\Enums\UserRole;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Models\User;
use App\Services\Assistant\Owner\IncomeRange;
use App\Services\Assistant\Owner\OwnerTool;
use App\Services\Assistant\Owner\ToolResult;
use App\Services\Reports\IncomeReportService;
use App\Support\LocalTime;
use Illuminate\Database\Eloquent\Builder;

/**
 * Walk-ins against online requests in a period (Phase 8D): orders and bookings MADE in the period by source, the new
 * walk-in customers, and the income of each source from the income report itself (IncomeReportService, so it equals
 * the Reports page). Walk-in customers have no account: the system never notifies them.
 */
class WalkInSummary extends OwnerTool
{
    public function __construct(private readonly IncomeReportService $reports) {}

    public function name(): string
    {
        return 'walk_in_summary';
    }

    public function description(): string
    {
        return 'Walk-ins in a period: walk-in and online printing orders and bookings made in the period, new walk-in customers, and walk-in vs online income (as the Income report shows it).';
    }

    public function parameters(): array
    {
        return self::rangeParameters();
    }

    protected function handle(array $params, User $owner): ToolResult
    {
        $range = $this->range($params);
        $result = new ToolResult($this->name());
        $period = IncomeRange::period($range);
        $inRange = fn (Builder $query) => $query
            ->when($range->from, fn (Builder $q, string $day) => $q->where('created_at', '>=', LocalTime::dayStartUtc($day)))
            ->when($range->to, fn (Builder $q, string $day) => $q->where('created_at', '<=', LocalTime::dayEndUtc($day)));
        $bySource = fn (Builder $query) => $inRange($query)->toBase()->selectRaw('source, COUNT(*) as total')->groupBy('source')->pluck('total', 'source');

        $orders = $bySource(PrintingOrder::query());
        $bookings = $bySource(PhotographyBooking::query());
        $walk = RecordSource::WalkIn->value;
        $online = RecordSource::Online->value;

        $result->data = [
            'period' => $result->label($range->label),
            'from' => $result->day($range->from),
            'to' => $result->day($range->to),
            'walk_in_orders' => $result->count((int) ($orders[$walk] ?? 0)),
            'online_orders' => $result->count((int) ($orders[$online] ?? 0)),
            'walk_in_bookings' => $result->count((int) ($bookings[$walk] ?? 0)),
            'online_bookings' => $result->count((int) ($bookings[$online] ?? 0)),
            'new_walk_in_customers' => $result->count($inRange(User::query()->where('role', UserRole::Customer)->where('is_walk_in', true))->count()),
            'note' => 'Counted on the day each order or booking was made. Walk-in customers have no account and are never notified by the system; the shop reaches them by the phone number on file.',
        ];

        if ($period === null) {
            $result->data['income_note'] = 'This period has not started yet, so there is no income for it.';
        } else {
            $r = $this->reports->build($period);
            $result->data += [
                'walk_in_income' => $result->money($r['sources']['walk_in']['cents']),
                'walk_in_payments' => $result->count($r['sources']['walk_in']['count']),
                'online_income' => $result->money($r['sources']['online']['cents']),
                'online_payments' => $result->count($r['sources']['online']['count']),
                'income' => $result->money($r['cents']),
            ];
            $result->link('Income report', route('owner.reports.income', IncomeRange::reportQuery($period)));
        }

        $result->link('Walk-in printing orders', route('owner.orders.index', ['source' => 'walk_in']));
        $result->link('Walk-in bookings', route('owner.bookings.index', ['source' => 'walk_in']));
        $result->link('Walk-in customers', route('owner.customers.index', ['type' => 'walk_in']));

        return $result;
    }
}
