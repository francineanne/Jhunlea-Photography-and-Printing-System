<?php

namespace App\Http\Controllers\Owner;

use App\Enums\PaymentMethod;
use App\Enums\PaymentRecordStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\Payments\PaymentLedgerRequest;
use App\Services\PaymentLedgerService;
use Illuminate\View\View;

/** The owner's payments ledger: every payment recorded at the shop, the recorded totals and what is still unpaid. */
class PaymentController extends Controller
{
    public function index(PaymentLedgerRequest $request, PaymentLedgerService $ledger): View
    {
        $filters = $request->filters();

        return view('owner.payments.index', [
            'payments' => $ledger->payments($filters),
            'totals' => $ledger->totals($filters),
            'awaiting' => $ledger->awaiting(),
            'filters' => $filters,
            'hasFilters' => $filters['method'] || $filters['status'] || $filters['search'] !== '' || $filters['from'] || $filters['to'],
            'methods' => PaymentMethod::cases(),
            'statuses' => PaymentRecordStatus::cases(),
        ]);
    }
}
