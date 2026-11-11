<?php

namespace App\Services\Assistant\Owner\Tools;

use App\Enums\PrintingOrderStatus;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Models\User;
use App\Services\Assistant\Owner\OwnerTool;
use App\Services\Assistant\Owner\ToolResult;
use App\Services\Counter\CounterService;
use App\Services\PaymentLedgerService;
use App\Support\Money;

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
            $result->rows[] = array_filter([
                'reference' => $record->reference_number,
                'customer' => $record->customer?->name,
                'kind' => $order ? 'printing order' : 'photography booking',
                'walk_in' => $record->isWalkIn() ? 'yes (no account: reached by the phone number on file, never notified)' : null,
                'status' => $record->status->label(),
                'amount_due' => $result->money(Money::toCents($record->confirmed_price)),
                // Whole Manila days, like the counter's Awaiting payment queue (Phase 8D)
                'days_waiting' => $result->count(CounterService::daysWaiting($record)),
                'session' => $record instanceof PhotographyBooking ? $result->moment($record->starts_at) : null,
            ], fn ($value) => $value !== null);
            // Opens the counter's record-payment window on this record (8B)
            $result->card($record->reference_number, route('owner.payments.index', ['pay' => $record->getMorphClass().':'.$record->id]));
        }

        $result->total = $count;
        $oldest = $rows->first();
        $result->data = array_filter([
            'awaiting_payment_count' => $result->count($count),
            'awaiting_payment_total' => $result->money($cents),
            'ready_for_pickup_unpaid' => $result->count((clone $this->ledger->awaitingOrders())->where('status', PrintingOrderStatus::ReadyForPickup)->count()),
            'oldest_waiting' => $oldest === null ? null : $oldest->reference_number.', '.$result->count(CounterService::daysWaiting($oldest)).' days',
            'note' => 'Awaiting payment is a confirmed price with no recorded payment yet. It is never income. Each is paid in full at the counter.',
        ], fn ($value) => $value !== null);
        $result->link('Counter: awaiting payment', route('owner.payments.index', ['awaiting' => 1]));
        $result->link('Payments ledger', route('owner.payments.index'));

        return $result;
    }
}
