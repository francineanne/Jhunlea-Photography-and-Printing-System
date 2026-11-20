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
            // value => label; Phase 10N: photography adds "Expired" right after the two request statuses (as the tabs do)
            'statuses' => match ($filters['kind']) {
                'printing' => collect(PrintingOrderStatus::cases())->mapWithKeys(fn (PrintingOrderStatus $s) => [$s->value => $s->label()])->all(),
                'photography' => collect(BookingStatus::cases())->flatMap(fn (BookingStatus $s) => $s === BookingStatus::Proposed
                    ? [$s->value => $s->label(), RequestHistoryService::EXPIRED => 'Expired']
                    : [$s->value => $s->label()])->all(),
                default => [],
            },
            'hasFilters' => $filters['kind'] || $filters['status'] || $filters['search'] !== '' || $filters['from'] || $filters['to'],
        ]);
    }
}
