<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
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
        ]);
    }
}
