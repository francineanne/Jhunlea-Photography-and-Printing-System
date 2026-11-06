<?php

namespace App\Http\Controllers\Owner;

use App\Enums\PaymentMethod;
use App\Enums\PaymentRecordStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\Payments\PaymentLedgerRequest;
use App\Models\Payment;
use App\Services\Counter\CounterService;
use App\Services\PaymentLedgerService;
use Illuminate\View\View;

/**
 * The owner's Payments page: the shop counter (Phase 8B: record payment, ready for pickup, awaiting payment) above the
 * ledger of every payment recorded at the shop, with the recorded totals. The ledger shows today by default.
 */
class PaymentController extends Controller
{
    public function index(PaymentLedgerRequest $request, PaymentLedgerService $ledger, CounterService $counter): View
    {
        $filters = $request->filters();
        $range = $request->range();
        // ?pay=printing_order:12 (from a detail page or a queue) opens the record-payment modal on that order or booking
        $pay = preg_match('/^(printing_order|photography_booking):(\d{1,10})$/', (string) $request->query('pay'), $match) ? ['type' => $match[1], 'id' => (int) $match[2]] : null;

        return view('owner.payments.index', [
            'payments' => $ledger->payments($filters),
            'totals' => $ledger->totals($filters),
            'awaiting' => $ledger->awaiting(),
            'filters' => $filters,
            'range' => $range,
            'hasFilters' => $filters['method'] || $filters['status'] || $filters['search'] !== '' || ! in_array($range, ['today'], true),
            'everPaid' => Payment::query()->exists(),
            'methods' => PaymentMethod::cases(),
            'statuses' => PaymentRecordStatus::cases(),
            'ready' => $counter->readyForPickup(),
            'readyCount' => $counter->readyCount(),
            'awaitingQueue' => $counter->awaitingPayment(),
            'awaitingCount' => $counter->awaitingCount(),
            'pay' => $pay,
        ]);
    }
}
