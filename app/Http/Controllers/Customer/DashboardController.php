<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The customer's home (Phase 5C): their own numbers, what needs their attention and their latest requests. */
class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardService $dashboard): View
    {
        return view('customer.dashboard', ['data' => $dashboard->customer($request->user())]);
    }
}
