<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\Reports\ReportPeriodRequest;
use App\Services\ReportService;
use Illuminate\View\View;

/** The owner's reports for a period: requests created and payments received (read only). */
class ReportController extends Controller
{
    public function index(ReportPeriodRequest $request, ReportService $reports): View
    {
        $period = $request->period();

        return view('owner.reports.index', [
            'period' => $period,
            'ranges' => ReportPeriodRequest::RANGES,
            'report' => $reports->build($period['from'], $period['to']),
        ]);
    }
}
