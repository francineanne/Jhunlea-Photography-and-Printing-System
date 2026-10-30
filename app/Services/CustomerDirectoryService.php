<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRecordStatus;
use App\Enums\PrintingOrderStatus;
use App\Enums\UserRole;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The owner's customer directory and one customer's page (Phase 6A), read only. Every number reuses an existing rule:
 * awaiting payment is PaymentLedgerService::awaitingOrders()/awaitingBookings(), recorded payments are the ledger's
 * totals() and payments() for that customer, open means not final in the status tables. The counts are subselects
 * on the one customers query, so the number of queries never depends on the number of customers.
 */
class CustomerDirectoryService
{
    public const PER_PAGE = 20;

    public function __construct(
        private readonly PaymentLedgerService $ledger,
        private readonly RequestHistoryService $history,
    ) {}

    /**
     * @param  array{search: string, awaiting: bool}  $filters
     * @return LengthAwarePaginator<int, User>
     */
    public function customers(array $filters): LengthAwarePaginator
    {
        $search = $filters['search'];

        return $this->customersQuery()
            ->select(['id', 'name', 'email', 'phone', 'created_at'])
            ->withCount(['printingOrders', 'photographyBookings'])
            ->withMax('printingOrders', 'created_at')
            ->withMax('photographyBookings', 'created_at')
            ->addSelect($this->awaitingCentsSubs())
            ->when($search !== '', function (Builder $query) use ($search) {
                // Bound parameters, and % _ \ typed by the owner are matched literally instead of as wildcards
                $like = '%'.addcslashes($search, '%_\\').'%';
                $query->where(fn (Builder $inner) => $inner->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('phone', 'like', $like));
            })
            ->when($filters['awaiting'], fn (Builder $query) => $this->whereAwaiting($query))
            ->latest()
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(function (User $customer) {
                $customer->setAttribute('awaiting_cents', (int) $customer->awaiting_order_cents + (int) $customer->awaiting_booking_cents);
                $latest = max((string) $customer->printing_orders_max_created_at, (string) $customer->photography_bookings_max_created_at);
                $customer->setAttribute('last_activity_at', $latest === '' ? null : Carbon::parse($latest, 'UTC'));

                return $customer;
            });
    }

    /** @return array{total: int, newThisMonth: int, open: int, awaiting: int} */
    public function tiles(): array
    {
        $monthStart = LocalTime::dayStartUtc(LocalTime::format(Carbon::now('UTC'), 'Y-m-01'));
        $openOrders = array_values(array_filter(PrintingOrderStatus::cases(), fn (PrintingOrderStatus $status) => ! $status->isFinal()));
        $openBookings = array_values(array_filter(BookingStatus::cases(), fn (BookingStatus $status) => ! $status->isFinal()));

        return [
            'total' => $this->customersQuery()->count(),
            'newThisMonth' => $this->customersQuery()->where('created_at', '>=', $monthStart)->count(),
            'open' => $this->customersQuery()->where(fn (Builder $query) => $query
                ->whereHas('printingOrders', fn (Builder $orders) => $orders->whereIn('status', $openOrders))
                ->orWhereHas('photographyBookings', fn (Builder $bookings) => $bookings->whereIn('status', $openBookings)))->count(),
            'awaiting' => $this->whereAwaiting($this->customersQuery())->count(),
        ];
    }

    /** A customer, or 404: the owner, another owner and a missing id are never found. */
    public function find(int|string $id): User
    {
        return $this->customersQuery()->whereKey($id)->firstOrFail();
    }

    /** @return array<string, mixed> */
    public function detail(User $customer): array
    {
        $own = fn (Builder $query) => $query->where('customer_id', $customer->id);
        $filters = ['method' => null, 'status' => null, 'search' => '', 'from' => null, 'to' => null, 'customer' => (int) $customer->id];

        return [
            'orders' => PrintingOrder::query()->where('customer_id', $customer->id)->count(),
            'bookings' => PhotographyBooking::query()->where('customer_id', $customer->id)->count(),
            'recorded' => $this->ledger->totals($filters),
            'awaitingCents' => $this->ledger->sumConfirmedCents($own($this->ledger->awaitingOrders())) + $this->ledger->sumConfirmedCents($own($this->ledger->awaitingBookings())),
            // Removed (soft deleted) requests are listed too, so their history stays visible to the shop
            'requests' => $this->history->page($customer, ['kind' => null, 'status' => null, 'search' => '', 'from' => null, 'to' => null], 'requests', withRemoved: true),
            'payments' => $this->ledger->payments($filters, 'payments'),
            'methods' => PaymentMethod::cases(),
            'recordedStatus' => PaymentRecordStatus::Recorded,
        ];
    }

    /** @return Builder<User> */
    private function customersQuery(): Builder
    {
        return User::query()->where('role', UserRole::Customer);
    }

    /**
     * Whole centavos awaiting payment for the customer of the outer row, as two subselects with their bindings (the
     * ledger's rule, one per kind); customers() adds them up.
     *
     * @return array<string, \Illuminate\Database\Query\Builder>
     */
    private function awaitingCentsSubs(): array
    {
        $sum = fn (Builder $query) => $query->whereColumn('customer_id', 'users.id')->toBase()->selectRaw('COALESCE(SUM(ROUND(confirmed_price * 100)), 0)');

        return [
            'awaiting_order_cents' => $sum($this->ledger->awaitingOrders()),
            'awaiting_booking_cents' => $sum($this->ledger->awaitingBookings()),
        ];
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    private function whereAwaiting(Builder $query): Builder
    {
        return $query->where(fn (Builder $inner) => $inner
            ->whereExists($this->ledger->awaitingOrders()->whereColumn('customer_id', 'users.id')->toBase())
            ->orWhereExists($this->ledger->awaitingBookings()->whereColumn('customer_id', 'users.id')->toBase()));
    }
}
