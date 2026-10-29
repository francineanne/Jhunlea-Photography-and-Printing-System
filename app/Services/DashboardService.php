<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\PrintingOrderStatus;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Models\User;
use App\Support\LocalTime;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The numbers and short lists of the customer dashboard and the owner overview (Phase 5C), read with a fixed number
 * of queries however much data exists. Every number reuses the rule the lists and the ledger already use: awaiting
 * payment is PaymentLedgerService's awaitingOrders()/awaitingBookings() (no second copy of that rule), recorded
 * payments today is the ledger's totals() for the shop's calendar day, and unread is NotificationFeed::unreadCount().
 */
class DashboardService
{
    /** How many rows each "needs attention" list shows. */
    public const LIST_LIMIT = 5;

    public const LATEST = 5;

    public function __construct(
        private readonly PaymentLedgerService $ledger,
        private readonly NotificationFeed $notifications,
        private readonly RequestHistoryService $history,
    ) {}

    /** @return array<string, mixed> */
    public function customer(User $customer): array
    {
        $now = Carbon::now('UTC');
        $mine = fn (Builder $query) => $query->where('customer_id', $customer->id);

        // Open = not final in the status table (not Rejected, Cancelled or Completed)
        $open = array_values(array_filter(PrintingOrderStatus::cases(), fn (PrintingOrderStatus $status) => ! $status->isFinal()));

        $unpaidOrders = $mine($this->ledger->awaitingOrders());
        $unpaidBookings = $mine($this->ledger->awaitingBookings());
        $toPayCents = $this->ledger->sumConfirmedCents($unpaidOrders) + $this->ledger->sumConfirmedCents($unpaidBookings);

        $needsInfo = $mine(PrintingOrder::query())->whereNotNull('info_request')->where('info_request', '!=', '')->latest()->limit(self::LIST_LIMIT)->get();
        $proposed = $mine(PhotographyBooking::query())->where('status', BookingStatus::Proposed)->latest()->limit(self::LIST_LIMIT)->get();
        $ready = $mine(PrintingOrder::query())->where('status', PrintingOrderStatus::ReadyForPickup)->with('recordedPayment')->latest()->limit(self::LIST_LIMIT)->get();
        // Ready orders already say what to pay, so the payment list leaves them out
        $toPay = (clone $unpaidOrders)->where('status', '!=', PrintingOrderStatus::ReadyForPickup)->latest()->limit(self::LIST_LIMIT)->get()
            ->concat((clone $unpaidBookings)->orderBy('starts_at')->limit(self::LIST_LIMIT)->get());

        $attention = collect()
            ->concat($needsInfo->map(fn (PrintingOrder $order) => $this->item('help', 'amber', $order->reference_number, 'The shop needs more information', 'Open the order to see what the shop asked.', route('customer.printing.show', $order))))
            ->concat($proposed->map(fn (PhotographyBooking $booking) => $this->item('schedule', 'blue', $booking->reference_number, 'A new time was proposed', 'Accept it or ask for another time.', route('customer.bookings.show', $booking))))
            ->concat($ready->map(fn (PrintingOrder $order) => $this->item('inventory_2', 'green', $order->reference_number, 'Ready for pickup',
                $order->recordedPayment || $order->confirmed_price === null || Money::toCents($order->confirmed_price) === 0 ? 'Collect it at the shop.' : 'Pay '.Money::format($order->confirmed_price).' at the shop when you collect it.', route('customer.printing.show', $order))))
            ->concat($toPay->map(fn (PrintingOrder|PhotographyBooking $payable) => $this->item('point_of_sale', 'red', $payable->reference_number, 'Amount to pay at the '.($payable instanceof PrintingOrder ? 'shop' : 'studio'),
                Money::format($payable->confirmed_price).($payable instanceof PrintingOrder ? ', paid face to face when you pick it up.' : ', paid face to face on the day of the shoot.'),
                ($payable instanceof PrintingOrder ? route('customer.printing.show', $payable) : route('customer.bookings.show', $payable)).'#payment')));

        $counts = $this->history->counts($customer);

        return [
            'activeOrders' => $mine(PrintingOrder::query())->whereIn('status', $open)->count(),
            'upcomingShoots' => $mine(PhotographyBooking::query())->where(fn (Builder $query) => $query
                ->where(fn (Builder $confirmed) => $confirmed->where('status', BookingStatus::Confirmed)->where('starts_at', '>', $now))
                ->orWhere(fn (Builder $proposed) => $proposed->where('status', BookingStatus::Proposed)->where('proposed_starts_at', '>', $now)))->count(),
            'toPayCents' => $toPayCents,
            'unread' => $this->notifications->unreadCount($customer),
            'attention' => $attention->values(),
            'latest' => $this->history->latest($customer, self::LATEST),
            'requests' => $counts['all'],
        ];
    }

    /** @return array<string, mixed> */
    public function owner(User $owner): array
    {
        $today = LocalTime::format(Carbon::now('UTC'), 'Y-m-d');
        $review = PrintingOrder::query()->whereIn('status', [PrintingOrderStatus::Submitted, PrintingOrderStatus::UnderReview]);
        $requests = PhotographyBooking::query()->where('status', BookingStatus::Requested);
        $shoots = PhotographyBooking::query()->where('status', BookingStatus::Confirmed)
            ->whereBetween('starts_at', [LocalTime::dayStartUtc($today), LocalTime::dayEndUtc($today)]);

        return [
            'today' => $today,
            'reviewCount' => (clone $review)->count(),
            'review' => (clone $review)->with('customer:id,name')->oldest()->oldest('id')->limit(self::LIST_LIMIT)->get(),
            'requestCount' => (clone $requests)->count(),
            'requests' => (clone $requests)->with(['customer:id,name', 'package'])->oldest()->oldest('id')->limit(self::LIST_LIMIT)->get(),
            'shootCount' => (clone $shoots)->count(),
            'shoots' => (clone $shoots)->with(['customer:id,name', 'package'])->orderBy('starts_at')->limit(self::LIST_LIMIT * 2)->get(),
            'awaiting' => $this->ledger->awaiting(),
            'recordedToday' => $this->ledger->totals(['method' => null, 'status' => null, 'search' => '', 'from' => $today, 'to' => $today]),
            'unread' => $this->notifications->unreadCount($owner),
        ];
    }

    /** @return array{icon: string, tone: string, reference: string, title: string, text: string, url: string} */
    private function item(string $icon, string $tone, string $reference, string $title, string $text, string $url): array
    {
        return compact('icon', 'tone', 'reference', 'title', 'text', 'url');
    }
}
