<?php

namespace App\Services\Assistant\Owner\Tools;

use App\Enums\BookingStatus;
use App\Enums\PrintingOrderStatus;
use App\Enums\RecordSource;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Models\User;
use App\Services\Assistant\Owner\OwnerTool;
use App\Services\Assistant\Owner\ToolResult;
use App\Services\Counter\CounterService;
use App\Services\PaymentLedgerService;
use App\Support\LocalTime;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * The counter right now (Phase 8D), from the counter's own services (CounterService, PaymentLedgerService): printing
 * orders ready for pickup (the unpaid ones first), the awaiting-payment queue (the longest waiting first, in Manila
 * days), today's recorded payments, and today's walk-in sessions still unpaid. Read only: recording a payment is done
 * by the owner at the counter.
 */
class CounterQueues extends OwnerTool
{
    public function __construct(
        private readonly CounterService $counter,
        private readonly PaymentLedgerService $ledger,
    ) {}

    public function name(): string
    {
        return 'counter_queues';
    }

    public function description(): string
    {
        return 'The counter on the Payments page right now: printing orders ready for pickup (unpaid first, what to do next), the awaiting-payment queue (oldest first, days waiting), today\'s payments (count, total, Cash, GCash) and today\'s walk-in sessions not paid yet.';
    }

    public function parameters(): array
    {
        return ['kind' => ['type' => 'string', 'enum' => ['all', 'ready_for_pickup', 'awaiting_payment', 'today_payments', 'walk_in_today'], 'default' => 'all']];
    }

    protected function handle(array $params, User $owner): ToolResult
    {
        $kind = $params['kind'];
        $wants = fn (string $part) => $kind === 'all' || $kind === $part;
        $result = new ToolResult($this->name());
        $pay = fn (PrintingOrder|PhotographyBooking $record) => route('owner.payments.index', ['pay' => $record->getMorphClass().':'.$record->id]);

        if ($wants('ready_for_pickup')) {
            $ready = collect($this->counter->readyForPickup()->items())->sortBy(fn (PrintingOrder $order) => $order->paymentStatus()->isSettled() ? 1 : 0)->values();
            $result->data['ready_for_pickup'] = $result->count($this->counter->readyCount());
            $result->data['ready_for_pickup_unpaid'] = $result->count((clone $this->ledger->awaitingOrders())->where('status', PrintingOrderStatus::ReadyForPickup)->count());

            foreach ($ready as $order) {
                $settled = $order->paymentStatus()->isSettled();
                $result->rows[] = array_filter([
                    'queue' => 'ready for pickup',
                    'reference' => $order->reference_number,
                    'customer' => $order->customer?->name,
                    'walk_in' => $order->isWalkIn() ? 'yes' : null,
                    'payment' => $order->paymentStatus()->label(),
                    'amount' => $order->confirmed_price === null ? null : $result->money(Money::toCents($order->confirmed_price)),
                    'next_step' => $settled ? 'paid: release it (Completed) when the customer picks it up' : 'record the full payment at the counter, then release it',
                ], fn ($value) => $value !== null);
                $result->card($order->reference_number, $settled ? route('owner.orders.show', $order) : $pay($order));
            }

            $result->link('Counter: ready for pickup', route('owner.payments.index', ['ready' => 1]));
        }

        if ($wants('awaiting_payment')) {
            $queue = $this->counter->awaitingPayment();
            $oldest = $queue->first();
            $result->data['awaiting_payment'] = $result->count($this->counter->awaitingCount());
            $result->data['awaiting_payment_total'] = $result->money($this->ledger->sumConfirmedCents($this->ledger->awaitingOrders()) + $this->ledger->sumConfirmedCents($this->ledger->awaitingBookings()));
            if ($oldest !== null) {
                $result->data['oldest_waiting'] = $oldest->reference_number.', '.$result->count(CounterService::daysWaiting($oldest)).' days';
            }

            if ($kind === 'awaiting_payment') {
                foreach ($queue->items() as $record) {
                    $result->rows[] = array_filter([
                        'queue' => 'awaiting payment',
                        'reference' => $record->reference_number,
                        'customer' => $record->customer?->name,
                        'walk_in' => $record->isWalkIn() ? 'yes' : null,
                        'kind' => $record instanceof PrintingOrder ? 'printing order' : 'photography booking',
                        'amount_due' => $result->money(Money::toCents($record->confirmed_price)),
                        'days_waiting' => $result->count(CounterService::daysWaiting($record)),
                    ], fn ($value) => $value !== null);
                    $result->card($record->reference_number, $pay($record));
                }
            }

            $result->link('Counter: awaiting payment', route('owner.payments.index', ['awaiting' => 1]));
        }

        $today = LocalTime::in(Carbon::now('UTC'))->format('Y-m-d');

        if ($wants('today_payments')) {
            $totals = $this->ledger->totals(['method' => null, 'status' => null, 'search' => '', 'from' => $today, 'to' => $today]);
            $result->data['today'] = $result->day($today);
            $result->data['income_today'] = $result->money($totals['cents']);
            $result->data['payments_today'] = $result->count($totals['count']);
            $result->data['cash_today'] = $result->money($totals['methods']['cash']['cents']);
            $result->data['gcash_today'] = $result->money($totals['methods']['gcash']['cents']);
            $result->link('Payments today', route('owner.payments.index', ['range' => 'today']));
        }

        if ($wants('walk_in_today')) {
            $sessions = PhotographyBooking::query()->with('customer:id,name')
                ->where('source', RecordSource::WalkIn)->where('status', BookingStatus::Confirmed)
                ->whereBetween('starts_at', [LocalTime::dayStartUtc($today), LocalTime::dayEndUtc($today)])
                ->whereDoesntHave('recordedPayment')
                ->orderBy('starts_at')->get();
            $result->data['walk_in_sessions_today_unpaid'] = $result->count($sessions->count());

            foreach ($sessions->take(self::MAX_ROWS) as $booking) {
                $result->rows[] = array_filter([
                    'queue' => 'walk-in session today, not paid yet',
                    'reference' => $booking->reference_number,
                    'customer' => $booking->customer?->name,
                    'session' => $result->moment($booking->starts_at),
                    'amount_due' => $booking->confirmed_price === null ? 'no confirmed price yet' : $result->money(Money::toCents($booking->confirmed_price)),
                ], fn ($value) => $value !== null);
                $result->card($booking->reference_number, $booking->confirmed_price !== null && (float) $booking->confirmed_price > 0 ? $pay($booking) : route('owner.bookings.show', $booking));
            }
        }

        if ($kind !== 'all') {
            $result->total = count($result->rows);
        }

        $result->link('Payments and counter', route('owner.payments.index'));

        return $result;
    }
}
