<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\PrintingOrderStatus;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Models\User;
use App\Support\LocalTime;
use BackedEnum;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One customer's printing orders and photography bookings in ONE list, newest first (Phase 5C).
 *
 * How: a UNION ALL of two small bound selects (kind, id, created_at), one per table, each already limited to this
 * customer and to the filters; the database sorts and paginates that union, so nothing is loaded into PHP to sort.
 * Then the page's rows (at most PER_PAGE) are loaded with one query per kind, with their payment and service or
 * package, and put back in the union's order. The number of queries never depends on the number of rows.
 */
class RequestHistoryService
{
    public const PER_PAGE = 15;

    public const KINDS = ['printing', 'photography'];

    /**
     * @param  array{kind: ?string, status: ?string, search: string, from: ?string, to: ?string}  $filters
     * @return LengthAwarePaginator<int, PrintingOrder|PhotographyBooking>
     */
    public function page(User $customer, array $filters): LengthAwarePaginator
    {
        $page = DB::query()->fromSub($this->union($customer, $filters), 'requests')
            ->orderByDesc('created_at')->orderBy('kind')->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return $page->setCollection($this->hydrate($page->getCollection()));
    }

    /** @return Collection<int, PrintingOrder|PhotographyBooking> the latest few, for the dashboard */
    public function latest(User $customer, int $limit): Collection
    {
        $rows = DB::query()->fromSub($this->union($customer, ['kind' => null, 'status' => null, 'search' => '', 'from' => null, 'to' => null]), 'requests')
            ->orderByDesc('created_at')->orderBy('kind')->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $this->hydrate($rows);
    }

    /** @return array{all: int, printing: int, photography: int} how many each tab holds, whatever the other filters */
    public function counts(User $customer): array
    {
        $printing = PrintingOrder::query()->where('customer_id', $customer->id)->count();
        $photography = PhotographyBooking::query()->where('customer_id', $customer->id)->count();

        return ['all' => $printing + $photography, 'printing' => $printing, 'photography' => $photography];
    }

    /** @param  array{kind: ?string, status: ?string, search: string, from: ?string, to: ?string}  $filters */
    private function union(User $customer, array $filters): QueryBuilder
    {
        $orders = $this->side(PrintingOrder::query(), 'printing', $customer, $filters, PrintingOrderStatus::tryFrom((string) $filters['status']),
            fn (Builder $query, string $like) => $query->orWhereHas('service', fn (Builder $service) => $service->where('name', 'like', $like)));
        $bookings = $this->side(PhotographyBooking::query(), 'photography', $customer, $filters, BookingStatus::tryFrom((string) $filters['status']),
            fn (Builder $query, string $like) => $query->orWhereHas('package', fn (Builder $package) => $package->where('name', 'like', $like)));

        return match ($filters['kind']) {
            'printing' => $orders,
            'photography' => $bookings,
            default => $orders->unionAll($bookings),
        };
    }

    /**
     * One side of the union: this customer's rows of one table, filtered. The status filter applies only to the kind it
     * belongs to (the request has already checked it against that kind).
     *
     * @param  Builder<PrintingOrder>|Builder<PhotographyBooking>  $query
     * @param  array{kind: ?string, status: ?string, search: string, from: ?string, to: ?string}  $filters
     */
    private function side(Builder $query, string $kind, User $customer, array $filters, ?BackedEnum $status, Closure $nameLike): QueryBuilder
    {
        $search = $filters['search'];

        return $query
            ->where('customer_id', $customer->id)
            ->when($status !== null && $filters['kind'] === $kind, fn (Builder $q) => $q->where('status', $status))
            ->when($search !== '', function (Builder $q) use ($search, $nameLike) {
                // Bound parameters, and % _ \ typed by the customer are matched literally instead of as wildcards
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn (Builder $inner) => $nameLike($inner->where('reference_number', 'like', $like), $like));
            })
            ->when($filters['from'], fn (Builder $q, string $from) => $q->where('created_at', '>=', LocalTime::dayStartUtc($from)))
            ->when($filters['to'], fn (Builder $q, string $to) => $q->where('created_at', '<=', LocalTime::dayEndUtc($to)))
            ->select([DB::raw("'{$kind}' as kind"), 'id', 'created_at'])
            ->toBase();
    }

    /**
     * The models behind the union rows, in the union's order: one query per kind, with what the list shows.
     *
     * @param  Collection<int, object>  $rows
     * @return Collection<int, PrintingOrder|PhotographyBooking>
     */
    private function hydrate(Collection $rows): Collection
    {
        $orderIds = $rows->where('kind', 'printing')->pluck('id')->all();
        $bookingIds = $rows->where('kind', 'photography')->pluck('id')->all();

        $orders = $orderIds === [] ? collect() : PrintingOrder::query()->with(['service', 'recordedPayment'])->whereKey($orderIds)->get()->keyBy('id');
        $bookings = $bookingIds === [] ? collect() : PhotographyBooking::query()->with(['package', 'recordedPayment'])->whereKey($bookingIds)->get()->keyBy('id');

        return $rows->map(fn ($row) => $row->kind === 'printing' ? $orders->get($row->id) : $bookings->get($row->id))->filter()->values();
    }
}
