<?php

namespace App\Services\Assistant\Owner\Tools;

use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Models\User;
use App\Services\Assistant\Owner\OwnerTool;
use App\Services\Assistant\Owner\ToolResult;
use App\Services\CustomerDirectoryService;
use App\Services\PaymentLedgerService;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * One customer (the customer detail page's numbers): orders, bookings, recorded payments, the amount awaiting payment
 * and their latest requests. By id from customer_search; never a phone number, an email, a note or a message.
 */
class CustomerProfile extends OwnerTool
{
    public function __construct(
        private readonly CustomerDirectoryService $directory,
        private readonly PaymentLedgerService $ledger,
    ) {}

    public function name(): string
    {
        return 'customer_profile';
    }

    public function description(): string
    {
        return 'One customer by customer_id (from customer_search): orders and bookings, recorded payments, amount awaiting payment, latest requests.';
    }

    public function parameters(): array
    {
        return ['customer_id' => ['type' => 'integer', 'required' => true, 'min' => 1]];
    }

    protected function handle(array $params, User $owner): ToolResult
    {
        $result = new ToolResult($this->name());

        try {
            $customer = $this->directory->find($params['customer_id']);
        } catch (ModelNotFoundException) {
            $result->question = 'Which customer do you mean? I could not find that one.';

            return $result;
        }

        $own = fn ($query) => $query->where('customer_id', $customer->id);
        $recorded = $this->ledger->totals(['method' => null, 'status' => null, 'search' => '', 'from' => null, 'to' => null, 'customer' => (int) $customer->id]);
        $orders = PrintingOrder::query()->where('customer_id', $customer->id);
        $bookings = PhotographyBooking::query()->where('customer_id', $customer->id);

        $result->data = [
            'name' => $customer->name,
            'customer_since' => $result->moment($customer->created_at),
            'orders' => $result->count((clone $orders)->count()),
            'bookings' => $result->count((clone $bookings)->count()),
            'recorded_payments' => $result->money($recorded['cents']),
            'payments' => $result->count($recorded['count']),
            'awaiting_payment' => $result->money($this->ledger->sumConfirmedCents($own($this->ledger->awaitingOrders())) + $this->ledger->sumConfirmedCents($own($this->ledger->awaitingBookings()))),
        ];

        $latest = collect()
            ->concat((clone $orders)->with(['customer:id,name', 'recordedPayment'])->latest()->latest('id')->limit(5)->get())
            ->concat((clone $bookings)->with(['customer:id,name', 'recordedPayment'])->latest()->latest('id')->limit(5)->get())
            ->sortByDesc('created_at')->take(5);

        foreach ($latest as $record) {
            $result->rows[] = $record instanceof PrintingOrder ? RecordFacts::order($record, $result) : RecordFacts::booking($record, $result);
        }

        $result->total = $result->data['orders'] + $result->data['bookings'];
        $result->card($customer->name, route('owner.customers.show', $customer));

        return $result;
    }
}
