<?php

namespace App\Http\Controllers\Owner;

use App\Enums\PrintingOrderStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\Orders\OrderListRequest;
use App\Models\PrintingOrder;
use App\Support\LocalTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** The owner's list of every printing order, and one order with everything the shop needs to work on it. */
class PrintingOrderController extends Controller
{
    private const PER_PAGE = 15;

    public function index(OrderListRequest $request): View
    {
        $status = $request->status();
        $search = $request->search();

        $orders = PrintingOrder::query()
            ->with(['customer', 'service'])
            ->withCount('files')
            ->when($status, fn (Builder $query) => $query->where('status', $status))
            ->when($search !== '', function (Builder $query) use ($search) {
                // Bound parameters, and % _ \ typed by the owner are matched literally instead of as wildcards
                $like = '%'.addcslashes($search, '%_\\').'%';
                $query->where(fn (Builder $inner) => $inner
                    ->where('reference_number', 'like', $like)
                    ->orWhereHas('customer', fn (Builder $customer) => $customer->where('name', 'like', $like)));
            })
            ->when($request->from(), fn (Builder $query, string $from) => $query->where('created_at', '>=', LocalTime::dayStartUtc($from)))
            ->when($request->to(), fn (Builder $query, string $to) => $query->where('created_at', '<=', LocalTime::dayEndUtc($to)))
            ->latest()
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('owner.orders.index', [
            'orders' => $orders,
            'status' => $status,
            'search' => $search,
            'from' => $request->from(),
            'to' => $request->to(),
            'hasFilters' => $status !== null || $search !== '' || $request->from() || $request->to(),
            // Every order, whatever the filters, like the tabs of the design
            'counts' => PrintingOrder::query()->toBase()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($total) => (int) $total),
            'statuses' => PrintingOrderStatus::cases(),
        ]);
    }

    public function show(PrintingOrder $order): View
    {
        Gate::authorize('view', $order);

        $order->load([
            'customer',
            'files' => fn ($query) => $query->orderBy('id'),
            'files.uploader',
            'statusHistories.changedBy',
        ]);

        // Which buttons to show; the service checks the same table again when one is pressed
        $moves = collect(array_keys($order->status->transitions()))
            ->map(fn (string $to) => PrintingOrderStatus::from($to))
            ->filter(fn (PrintingOrderStatus $to) => $order->status->canMoveTo($to, UserRole::Owner))
            ->values();

        return view('owner.orders.show', [
            'order' => $order,
            'moves' => $moves,
            'canRequestInfo' => $order->status->allowsInfoRequest(),
            'canChangePrice' => $order->status->allowsPriceChange(),
        ]);
    }
}
