<?php

namespace App\Http\Controllers\Customer;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $mine = PrintingOrder::query()->where('customer_id', $request->user()->id);

        return view('customer.dashboard', [
            'orderCount' => (clone $mine)->count(),
            'needsInfoCount' => (clone $mine)->whereNotNull('info_request')->where('info_request', '!=', '')->count(),
            'bookingCount' => PhotographyBooking::query()->where('customer_id', $request->user()->id)->count(),
            // A time the studio offered is waiting for the customer's answer
            'proposedCount' => PhotographyBooking::query()->where('customer_id', $request->user()->id)->where('status', BookingStatus::Proposed)->count(),
        ]);
    }
}
