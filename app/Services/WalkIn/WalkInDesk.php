<?php

namespace App\Services\WalkIn;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\PrintingOrderStatus;
use App\Enums\RecordSource;
use App\Enums\UserRole;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Models\User;
use App\Support\LocalTime;
use App\Support\ShopSettings;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Phase 10J: what the Walk-in page reads (read only). Walk-in records only (source walk_in); the same statuses and
 * payment rules as online ones, so nothing here decides anything: it only lists what needs the owner, because a
 * walk-in is never notified and the shop calls them instead.
 */
class WalkInDesk
{
    public const PER_PAGE = 20;

    public function __construct(private readonly ShopSettings $settings) {}

    /**
     * The queues under "Needs attention".
     *
     * @return array{awaiting: Collection<int, PhotographyBooking>, balance: Collection<int, PhotographyBooking>, ready: Collection<int, array{order: PrintingOrder, days: int, unclaimable: bool}>, prepay: Collection<int, PrintingOrder>}
     */
    public function attention(): array
    {
        $now = Carbon::now('UTC');

        // Bookings waiting for their downpayment, nearest deadline first (the shop calls before it passes)
        $awaiting = PhotographyBooking::query()->where('source', RecordSource::WalkIn)
            ->where('status', BookingStatus::AwaitingDownpayment)
            ->with('customer:id,name,phone')->orderBy('downpayment_due_at')->limit(50)->get();

        // Shoots in the next 7 days with something still to pay
        $balance = PhotographyBooking::query()->where('source', RecordSource::WalkIn)
            ->where('status', BookingStatus::Confirmed)
            ->whereBetween('starts_at', [$now, $now->copy()->addDays(7)])
            ->with(['customer:id,name,phone', 'payments'])->orderBy('starts_at')->limit(50)->get()
            ->filter(fn (PhotographyBooking $booking) => ! $booking->paymentStatus()->isSettled())->values();

        // Ready for pickup and not paid yet, with the days since it became ready and the Unclaimed rule
        $after = (int) $this->settings->paymentRule('unclaimed_after_days');
        $ready = PrintingOrder::query()->where('source', RecordSource::WalkIn)
            ->where('status', PrintingOrderStatus::ReadyForPickup)
            ->with(['customer:id,name,phone', 'payments', 'statusHistories' => fn ($query) => $query->where('to_status', PrintingOrderStatus::ReadyForPickup)->latest('id')])
            ->oldest('updated_at')->limit(50)->get()
            ->filter(fn (PrintingOrder $order) => ! $order->paymentStatus()->isSettled())
            ->map(function (PrintingOrder $order) use ($after) {
                $since = $order->statusHistories->first()?->created_at ?? $order->updated_at;
                $days = (int) max(0, LocalTime::in($since)->startOfDay()->diffInDays(LocalTime::in(Carbon::now('UTC'))->startOfDay()));

                return ['order' => $order, 'days' => $days, 'unclaimable' => $after > 0 && $days >= $after];
            })->values();

        // Accepted orders that must be paid before printing and are not paid yet
        $prepay = PrintingOrder::query()->where('source', RecordSource::WalkIn)
            ->where('status', PrintingOrderStatus::Accepted)->where('requires_prepayment', true)
            ->with(['customer:id,name,phone', 'payments'])->oldest('id')->limit(50)->get()
            ->filter(fn (PrintingOrder $order) => ! $order->paymentStatus()->isSettled())->values();

        return compact('awaiting', 'balance', 'ready', 'prepay');
    }

    /**
     * Walk-in customers, the most recent visit first: their open orders and bookings and their last visit.
     */
    public function customers(string $search = ''): LengthAwarePaginator
    {
        $closedOrders = array_map(fn (PrintingOrderStatus $status) => $status->value, array_filter(PrintingOrderStatus::cases(), fn (PrintingOrderStatus $status) => $status->isClosed()));
        $closedBookings = array_map(fn (BookingStatus $status) => $status->value, array_filter(BookingStatus::cases(), fn (BookingStatus $status) => $status->isClosed()));
        $search = trim($search);
        $like = '%'.addcslashes($search, '%_\\').'%';
        $digits = (string) preg_replace('/\D/', '', (string) preg_replace('/^\+?63(?=9)/', '0', (string) preg_replace('/[\s\-.()]/', '', $search)));

        return User::query()->where('role', UserRole::Customer)->where('is_walk_in', true)
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner->where('name', 'like', $like)
                ->when(strlen($digits) >= 3, fn (Builder $phone) => $phone->orWhere('phone', 'like', '%'.$digits.'%'))))
            ->withCount([
                'printingOrders as open_orders_count' => fn (Builder $query) => $query->whereNotIn('status', $closedOrders),
                'photographyBookings as open_bookings_count' => fn (Builder $query) => $query->whereNotIn('status', $closedBookings),
            ])
            ->withMax('printingOrders as last_order_at', 'created_at')
            ->withMax('photographyBookings as last_booking_at', 'created_at')
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->paginate(self::PER_PAGE, ['id', 'name', 'phone', 'prepay_required', 'created_at', 'updated_at'])
            ->withQueryString();
    }

    /** The last visit of a listed walk-in: their newest order or booking, else when they were added. */
    public static function lastVisit(User $customer): Carbon
    {
        return collect([$customer->last_order_at, $customer->last_booking_at, $customer->created_at])
            ->filter()->map(fn ($value) => Carbon::parse($value, 'UTC'))->max();
    }

    /** True when a payment status means nothing is still due. */
    public static function settled(PaymentStatus $status): bool
    {
        return $status->isSettled();
    }
}
