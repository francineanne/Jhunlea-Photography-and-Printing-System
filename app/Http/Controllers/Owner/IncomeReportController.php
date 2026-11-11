<?php

namespace App\Http\Controllers\Owner;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\Reports\EndOfDayRequest;
use App\Http\Requests\Owner\Reports\IncomeReportRequest;
use App\Models\Payment;
use App\Models\PrintingOrder;
use App\Services\Audit\AuditLogger;
use App\Services\PaymentLedgerService;
use App\Services\Reports\IncomePeriod;
use App\Services\Reports\IncomeReportService;
use App\Services\Reports\ReportViewPreference;
use App\Support\LocalTime;
use App\Support\ShopSettings;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The owner's income report (Phase 8C): the page (daily, weekly, monthly, custom, all time), the printable end-of-day
 * summary and the CSV of the period's payments. Read only; every figure comes from IncomeReportService, which reuses
 * the Payments ledger's own rules.
 */
class IncomeReportController extends Controller
{
    public const CSV_COLUMNS = ['Payment no.', 'Date', 'Time', 'Customer', 'Reference', 'Type', 'Item or package', 'Source', 'Method', 'GCash reference', 'Amount (PHP)', 'Status', 'Recorded by'];

    public function __construct(
        private readonly IncomeReportService $reports,
        private readonly PaymentLedgerService $ledger,
        private readonly ReportViewPreference $preference,
    ) {}

    public function index(IncomeReportRequest $request): View
    {
        $owner = $request->user();
        $period = $request->period($this->preference->for($owner));
        $this->preference->remember($owner, $period->view);

        return view('owner.reports.income', [
            'period' => $period,
            'views' => IncomePeriod::VIEWS,
            'report' => $this->reports->build($period),
            'payments' => $this->ledger->payments($period->filters()),
        ]);
    }

    public function endOfDay(EndOfDayRequest $request, ShopSettings $shop): View
    {
        $period = IncomePeriod::resolve('daily', $request->day(), null, null, LocalTime::in(Carbon::now('UTC')));
        app(AuditLogger::class)->record(AuditAction::EndOfDayOpened, null, ['date' => $period->date]);

        return view('owner.reports.end-of-day', [
            'period' => $period,
            'report' => $this->reports->build($period),
            'day' => $this->reports->dayPayments($period->date),
            'shop' => $shop->business(),
        ]);
    }

    public function csv(IncomeReportRequest $request): StreamedResponse
    {
        $period = $request->period($this->preference->for($request->user()));
        $name = 'jhunlea-income-'.($period->from === null && $period->to === null ? 'all-time' : ($period->from ?? 'start').'-to-'.($period->to ?? 'today')).'.csv';
        $rows = $this->ledger->listing($period->filters())->oldest('received_at')->oldest('id');
        app(AuditLogger::class)->record(AuditAction::ReportCsvExported, null, ['view' => $period->view, 'from' => $period->from, 'to' => $period->to, 'rows' => (clone $rows)->count()]);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // so Excel reads UTF-8 and the peso sign
            fputcsv($out, self::CSV_COLUMNS, escape: '');

            foreach ($rows->lazy(500) as $payment) {
                fputcsv($out, array_map([self::class, 'cell'], self::row($payment)), escape: '');
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /** @return list<string> */
    private static function row(Payment $payment): array
    {
        $payable = $payment->payable;
        $received = LocalTime::in($payment->received_at);

        return [
            $payment->reference_number,
            $received->format('Y-m-d'),
            $received->format('H:i'),
            (string) ($payable?->customer?->name ?? 'Deleted account'),
            (string) ($payable?->reference_number ?? ''),
            $payable === null ? '' : ($payable instanceof PrintingOrder ? 'Printing order' : 'Photoshoot'),
            $payable === null ? '' : ($payable instanceof PrintingOrder ? $payable->serviceName() : (string) ($payable->snapshot['package']['name'] ?? 'Photoshoot')),
            $payable === null ? '' : ($payable->isWalkIn() ? 'Walk-in' : 'Online'),
            $payment->method->label(),
            (string) $payment->gcash_reference,
            number_format((float) $payment->amount, 2, '.', ''),
            $payment->status->label(),
            (string) ($payment->receiver?->name ?? 'Deleted account'),
        ];
    }

    /** A cell a spreadsheet keeps as text: a value that starts like a formula gets a leading apostrophe. */
    public static function cell(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
