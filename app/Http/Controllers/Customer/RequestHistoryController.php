<?php

namespace App\Http\Controllers\Customer;

use App\Enums\BookingStatus;
use App\Enums\PrintingOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\RequestHistoryRequest;
use App\Services\RequestHistoryService;
use Illuminate\View\View;

/** All of the customer's printing orders and photography bookings in one list (Phase 5C). */
class RequestHistoryController extends Controller
{
    public function __invoke(RequestHistoryRequest $request, RequestHistoryService $history): View
    {
        $filters = $request->filters();

        return view('customer.requests.index', [
            'requests' => $history->page($request->user(), $filters),
            'counts' => $history->counts($request->user()),
            'filters' => $filters,
            'statuses' => match ($filters['kind']) {
                'printing' => PrintingOrderStatus::cases(),
                'photography' => BookingStatus::cases(),
                default => [],
            },
            'hasFilters' => $filters['kind'] || $filters['status'] || $filters['search'] !== '' || $filters['from'] || $filters['to'],
        ]);
    }
}
