<?php

namespace App\Services\Assistant\Owner\Tools;

use App\Enums\PrintingOrderStatus;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Models\User;
use App\Services\Assistant\Owner\OwnerTool;
use App\Services\Assistant\Owner\ToolResult;
use App\Services\PaymentLedgerService;
use App\Support\Money;
use Illuminate\Support\Carbon;

/** Who still has to pay, how much and for how many days: the ledger's awaiting rule (a confirmed price, no payment). */
class UnpaidBalances extends OwnerTool
{
    public function __construct(private readonly PaymentLedgerService $ledger) {}

    public function name(): string
    {
        return 'unpaid_balances';
    }

    public function description(): string
    {
        return 'Orders and bookings with a confirmed price and no recorded payment yet ("awaiting payment"): who, how much, how many days, and which orders are ready for pickup but unpaid.';
    }

    public function parameters(): array
    {
        return ['kind' => ['type' => 'string', 'enum' => ['all', 'orders', 'bookings', 'ready_for_pickup'], 'default' => 'all']];
    }

    protected function handle(array $params, User $owner): ToolResult
    {
        $result = new ToolResult($this->name());
        $orders = $this->ledger->awaitingOrders()
            ->when($params['kind'] === 'ready_for_pickup', fn ($query) => $query->where('status', PrintingOrderStatus::ReadyForPickup));
        $bookings = $this->ledger->awaitingBookings();
        $withOrders = $params['kind'] !== 'bookings';
        $withBookings = in_array($params['kind'], ['all', 'bookings'], true);

        $count = ($withOrders ? (clone $orders)->count() : 0) + ($withBookings ? (clone $bookings)->count() : 0);
        $cents = ($withOrders ? $this->ledger->sumConfirmedCents($orders) : 0) + ($withBookings ? $this->ledger->sumConfirmedCents($bookings) : 0);

        $rows = collect()
            ->concat($withOrders ? (clone $orders)->with('customer:id,name')->oldest('id')->limit(self::MAX_ROWS)->get() : [])
            ->concat($withBookings ? (clone $bookings)->with('customer:id,name')->orderBy('starts_at')->limit(self::MAX_ROWS)->get() : [])
            ->sortBy('created_at')->take(self::MAX_ROWS);

        foreach ($rows as $record) {
            $order = $record instanceof PrintingOrder;
            $result->rows[] = [
                'reference' => $record->reference_number,
                'customer' => $record->customer?->name,
                'kind' => $order ? 'printing order' : 'photography booking',
                'status' => $record->status->label(),
                'amount_due' => $result->money(Money::toCents($record->confirmed_price)),
                'days_waiting' => $result->count((int) $record->created_at->diffInDays(Carbon::now())),
                'session' => $record instanceof PhotographyBooking ? $result->moment($record->starts_at) : null,
            ];
            $result->card($record->reference_number, $order ? route('owner.orders.show', $record) : route('owner.bookings.show', $record));
        }

        $result->total = $count;
        $result->data = [
            'awaiting_payment_count' => $result->count($count),
            'awaiting_payment_total' => $result->money($cents),
            'ready_for_pickup_unpaid' => $result->count((clone $this->ledger->awaitingOrders())->where('status', PrintingOrderStatus::ReadyForPickup)->count()),
        ];
        $result->link('Payments ledger', route('owner.payments.index'));

        return $result;
    }
}
