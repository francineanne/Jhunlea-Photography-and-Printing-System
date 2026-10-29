<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The owner overview (Phase 5C): what waits for the shop today, read from the same rules as the lists and the ledger. */
class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardService $dashboard): View
    {
        return view('owner.dashboard', ['data' => $dashboard->owner($request->user())]);
    }
}
