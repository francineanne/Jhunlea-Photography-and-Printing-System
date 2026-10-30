<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRecordStatus;
use App\Enums\PrintingOrderStatus;
use App\Models\Payment;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Support\LocalTime;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;

/**
 * What the owner's payments ledger reads: the payments (recorded and voided), the totals of RECORDED payments for the
 * filtered period by method (voided ones never count), and the orders and bookings that wait for their payment. The
 * totals are recorded payments only, never revenue or sales: estimates and unpaid prices are not in them.
 */
class PaymentLedgerService
{
    public const PER_PAGE = 20;

    /** How many orders and how many bookings the "awaiting payment" list shows; the totals count all of them. */
    public const AWAITING_LIMIT = 25;

    /**
     * @param  array{method: ?PaymentMethod, status: ?PaymentRecordStatus, search: string, from: ?string, to: ?string, customer?: int}  $filters
     * @return LengthAwarePaginator<int, Payment>
     */
    public function payments(array $filters, string $pageName = 'page'): LengthAwarePaginator
    {
        return $this->filtered($filters)
            ->when($filters['status'], fn (Builder $query, PaymentRecordStatus $status) => $query->where('status', $status))
            ->with(['receiver:id,name', 'voider:id,name'])
            ->with(['payable' => fn (MorphTo $morph) => $morph->morphWith([
                PrintingOrder::class => ['customer:id,name'],
                PhotographyBooking::class => ['customer:id,name'],
            ])])
            ->latest('received_at')
            ->latest('id')
            ->paginate(self::PER_PAGE, pageName: $pageName)
            ->withQueryString();
    }

    /**
     * Recorded payments in the filtered period, in whole centavos, by method and in all. The status filter does not
     * apply: voided payments are never counted.
     *
     * @param  array{method: ?PaymentMethod, status: ?PaymentRecordStatus, search: string, from: ?string, to: ?string, customer?: int}  $filters
     * @return array{methods: array<string, array{count: int, cents: int}>, count: int, cents: int}
     */
    public function totals(array $filters): array
    {
        $rows = $this->filtered($filters)
            ->where('status', PaymentRecordStatus::Recorded)
            ->toBase()
            ->selectRaw('method, COUNT(*) as total, SUM(ROUND(amount * 100)) as cents')
            ->groupBy('method')
            ->get()
            ->keyBy('method');

        $methods = collect(PaymentMethod::cases())->mapWithKeys(fn (PaymentMethod $method) => [$method->value => [
            'count' => (int) ($rows[$method->value]->total ?? 0),
            'cents' => (int) ($rows[$method->value]->cents ?? 0),
        ]])->all();

        return [
            'methods' => $methods,
            'count' => array_sum(array_column($methods, 'count')),
            'cents' => array_sum(array_column($methods, 'cents')),
        ];
    }

    /**
     * Orders and bookings with a confirmed price above zero and no recorded payment, that are still open (not
     * rejected, cancelled or completed): the payments the shop is waiting for.
     *
     * @return array{orders: Collection<int, PrintingOrder>, bookings: Collection<int, PhotographyBooking>, count: int, cents: int}
     */
    public function awaiting(): array
    {
        $orders = $this->awaitingOrders();
        $bookings = $this->awaitingBookings();

        return [
            'orders' => (clone $orders)->with('customer:id,name')->oldest('id')->limit(self::AWAITING_LIMIT)->get(),
            'bookings' => (clone $bookings)->with('customer:id,name')->orderBy('starts_at')->limit(self::AWAITING_LIMIT)->get(),
            'count' => (clone $orders)->count() + (clone $bookings)->count(),
            'cents' => $this->sumConfirmedCents($orders) + $this->sumConfirmedCents($bookings),
        ];
    }

    /**
     * THE rule for an order that waits for its payment (the ledger, the owner overview and the customer dashboard all
     * use it): accepted and still open, a confirmed price above zero, no recorded payment. Never the estimate.
     *
     * @return Builder<PrintingOrder>
     */
    public function awaitingOrders(): Builder
    {
        return PrintingOrder::query()
            ->whereIn('status', [PrintingOrderStatus::Accepted, PrintingOrderStatus::Processing, PrintingOrderStatus::ReadyForPickup])
            ->where('confirmed_price', '>', 0)
            ->whereDoesntHave('recordedPayment');
    }

    /**
     * THE rule for a photoshoot that waits for its payment: confirmed, a confirmed price above zero, no recorded payment.
     *
     * @return Builder<PhotographyBooking>
     */
    public function awaitingBookings(): Builder
    {
        return PhotographyBooking::query()
            ->where('status', BookingStatus::Confirmed)
            ->where('confirmed_price', '>', 0)
            ->whereDoesntHave('recordedPayment');
    }

    /** Whole centavos of the confirmed prices a query finds (one query). */
    public function sumConfirmedCents(Builder $query): int
    {
        return (int) (clone $query)->toBase()->selectRaw('SUM(ROUND(confirmed_price * 100)) as cents')->value('cents');
    }

    /**
     * The date range (the shop's calendar days, as UTC ranges), method and search. Search matches the PAY reference,
     * the GCash reference, the PR or PB reference and the customer's name; % _ \ are matched literally.
     *
     * @param  array{method: ?PaymentMethod, status: ?PaymentRecordStatus, search: string, from: ?string, to: ?string, customer?: int}  $filters
     * @return Builder<Payment>
     */
    private function filtered(array $filters): Builder
    {
        $search = $filters['search'];

        return Payment::query()
            // One customer's payments (Phase 6A): on their orders and bookings, removed ones included
            ->when($filters['customer'] ?? null, fn (Builder $query, int $customer) => $query->where(fn (Builder $own) => $own
                ->where(fn (Builder $orders) => $orders->where('payable_type', 'printing_order')->whereIn('payable_id', PrintingOrder::withTrashed()->select('id')->where('customer_id', $customer)))
                ->orWhere(fn (Builder $bookings) => $bookings->where('payable_type', 'photography_booking')->whereIn('payable_id', PhotographyBooking::withTrashed()->select('id')->where('customer_id', $customer)))))
            ->when($filters['from'], fn (Builder $query, string $from) => $query->where('received_at', '>=', LocalTime::dayStartUtc($from)))
            ->when($filters['to'], fn (Builder $query, string $to) => $query->where('received_at', '<=', LocalTime::dayEndUtc($to)))
            ->when($filters['method'], fn (Builder $query, PaymentMethod $method) => $query->where('method', $method))
            ->when($search !== '', function (Builder $query) use ($search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $query->where(fn (Builder $inner) => $inner
                    ->where('reference_number', 'like', $like)
                    ->orWhere('gcash_reference', 'like', $like)
                    ->orWhereHasMorph('payable', [PrintingOrder::class, PhotographyBooking::class], fn (Builder $payable) => $payable
                        ->where('reference_number', 'like', $like)
                        ->orWhereHas('customer', fn (Builder $customer) => $customer->where('name', 'like', $like))));
            });
    }
}
