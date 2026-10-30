<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\PrintingOrderStatus;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Support\LocalTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The owner's reports (Phase 6A) for a period of the shop's calendar days: requests CREATED in the period and payments
 * RECEIVED in the period. Money is only ever "Recorded payments" (the ledger's totals(), voided never counted) and
 * "Awaiting payment right now" (the ledger's awaiting rule, not limited to the period and never added to the recorded
 * total). An estimate is never counted. A fixed number of queries, however much data exists.
 */
class ReportService
{
    public const TOP = 5;

    public function __construct(private readonly PaymentLedgerService $ledger) {}

    /** @return array<string, mixed> */
    public function build(?string $from, ?string $to): array
    {
        $orders = $this->inPeriod(PrintingOrder::query(), $from, $to);
        $bookings = $this->inPeriod(PhotographyBooking::query(), $from, $to);
        $awaitingOrders = $this->ledger->awaitingOrders();
        $awaitingBookings = $this->ledger->awaitingBookings();

        return [
            'orderStatuses' => $this->perStatus($orders, PrintingOrderStatus::cases()),
            'bookingStatuses' => $this->perStatus($bookings, BookingStatus::cases()),
            // The snapshot only, never the live row: no extra query, and a removed service or package keeps its name
            'topServices' => $this->top($orders, PrintingOrder::query(), 'printing_service_id', fn (PrintingOrder $order) => $order->snapshot['service']['name'] ?? 'Printing service'),
            'topPackages' => $this->top($bookings, PhotographyBooking::query(), 'photography_package_id', fn (PhotographyBooking $booking) => $booking->snapshot['package']['name'] ?? 'Photography package'),
            'recorded' => $this->ledger->totals(['method' => null, 'status' => null, 'search' => '', 'from' => $from, 'to' => $to]),
            'awaiting' => [
                'count' => (clone $awaitingOrders)->count() + (clone $awaitingBookings)->count(),
                'cents' => $this->ledger->sumConfirmedCents($awaitingOrders) + $this->ledger->sumConfirmedCents($awaitingBookings),
            ],
        ];
    }

    /**
     * Requests created in the period (the shop's calendar days as UTC ranges); no dates means all time.
     *
     * @template TModel of PrintingOrder|PhotographyBooking
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function inPeriod(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn (Builder $q, string $day) => $q->where('created_at', '>=', LocalTime::dayStartUtc($day)))
            ->when($to, fn (Builder $q, string $day) => $q->where('created_at', '<=', LocalTime::dayEndUtc($day)));
    }

    /**
     * Every status of the enum in the status table's order, zeros included (one grouped query).
     *
     * @param  list<PrintingOrderStatus>|list<BookingStatus>  $statuses
     * @return array{total: int, rows: list<array{status: PrintingOrderStatus|BookingStatus, count: int}>}
     */
    private function perStatus(Builder $query, array $statuses): array
    {
        $counts = (clone $query)->toBase()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $rows = array_map(fn ($status) => ['status' => $status, 'count' => (int) ($counts[$status->value] ?? 0)], $statuses);

        return ['total' => array_sum(array_column($rows, 'count')), 'rows' => $rows];
    }

    /**
     * The most ordered services or packages in the period, by count, ties broken by the lower id (the same order every
     * time). The name is read from the snapshot of that item's latest request in the period, so a renamed or removed
     * service or package still reads as it was ordered. Two queries.
     *
     * @return Collection<int, array{name: string, count: int}>
     */
    private function top(Builder $period, Builder $models, string $column, \Closure $name): Collection
    {
        $groups = (clone $period)->toBase()
            ->selectRaw("{$column} as item, COUNT(*) as total, MAX(id) as latest_id")
            ->groupBy($column)
            ->orderByDesc('total')->orderBy($column)
            ->limit(self::TOP)
            ->get();

        $latest = $groups->isEmpty() ? collect() : $models->whereKey($groups->pluck('latest_id')->all())->get()->keyBy('id');

        return $groups->map(fn ($group) => ['name' => $latest->has($group->latest_id) ? $name($latest->get($group->latest_id)) : '—', 'count' => (int) $group->total])->values();
    }
}
