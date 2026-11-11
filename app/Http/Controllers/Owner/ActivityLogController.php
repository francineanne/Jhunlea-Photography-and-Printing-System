<?php

namespace App\Http\Controllers\Owner;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\ActivityLogRequest;
use App\Models\AuditLog;
use App\Services\Audit\ActivityLogService;
use App\Services\Audit\AuditChain;
use App\Services\Audit\AuditLogger;
use App\Services\Audit\AuditSubject;
use App\Support\LocalTime;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The owner's Activity log (Phase 8E): the audit trail, read only, with filters, search, the integrity line and a CSV
 * export (the export itself is logged). There is no route that edits or deletes a row.
 */
class ActivityLogController extends Controller
{
    public const CSV_COLUMNS = ['Time', 'Actor', 'Action', 'Subject type', 'Reference', 'Summary', 'Details', 'IP'];

    public function __construct(private readonly ActivityLogService $activity) {}

    public function index(ActivityLogRequest $request, AuditChain $chain): View
    {
        $filters = $request->filters();
        $rows = $this->activity->page($filters);

        return view('owner.activity.index', [
            'rows' => $rows,
            'links' => $this->activity->links($rows->items()),
            'filters' => $filters,
            'groups' => AuditAction::GROUPS,
            'ranges' => ActivityLogRequest::RANGES,
            'startedAt' => $this->activity->startedAt(),
            'quick' => $chain->quick(),
            'full' => $chain->lastFull(),
        ]);
    }

    public function csv(ActivityLogRequest $request, AuditLogger $logger): StreamedResponse
    {
        $filters = $request->filters();
        $query = $this->activity->query($filters)->orderBy('id');
        $count = (clone $query)->count();

        // The export is logged first (with the filters and the row count), so it is part of the trail it exports
        $logger->record(AuditAction::AuditExported, null, ['group' => $filters['group'], 'from' => $filters['from'], 'to' => $filters['to'], 'search' => $filters['search'], 'rows' => $count]);

        $name = 'jhunlea-activity-'.($filters['from'] ?? 'start').'-to-'.($filters['to'] ?? 'today').'.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, self::CSV_COLUMNS, escape: '');

            foreach ($query->lazy(500) as $row) {
                fputcsv($out, array_map([IncomeReportController::class, 'cell'], self::row($row)), escape: '');
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /** @return list<string> */
    private static function row(AuditLog $row): array
    {
        return [
            LocalTime::format($row->occurred_at, 'Y-m-d H:i:s'),
            $row->actor_name.' ('.$row->actor_role.')',
            $row->action->label(),
            $row->subject_type === null ? '' : (AuditSubject::NAMES[$row->subject_type] ?? $row->subject_type),
            (string) $row->subject_label,
            $row->summary,
            self::details($row->meta ?? []),
            (string) $row->ip,
        ];
    }

    /** The whitelisted meta, flattened: "amount_cents=50000; method=cash". @param array<string, mixed> $meta */
    public static function details(array $meta, string $prefix = ''): string
    {
        $parts = [];

        foreach ($meta as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $parts[] = is_array($value) ? self::details($value, $name) : $name.'='.(is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value);
        }

        return implode('; ', array_filter($parts, fn (string $part) => $part !== ''));
    }
}
