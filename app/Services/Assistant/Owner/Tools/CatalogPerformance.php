<?php

namespace App\Services\Assistant\Owner\Tools;

use App\Models\User;
use App\Services\Assistant\Owner\OwnerTool;
use App\Services\Assistant\Owner\ToolResult;
use App\Services\ReportService;

/** The most requested printing services and photography packages in a period (the reports page's own lists). */
class CatalogPerformance extends OwnerTool
{
    public function __construct(private readonly ReportService $reports) {}

    public function name(): string
    {
        return 'catalog_performance';
    }

    public function description(): string
    {
        return 'The most requested printing services and photography packages in a period (by number of requests, not money).';
    }

    public function parameters(): array
    {
        return self::rangeParameters('this_month');
    }

    protected function handle(array $params, User $owner): ToolResult
    {
        $range = $this->range($params, 'this_month');
        $result = new ToolResult($this->name());
        $report = $this->reports->build($range->from, $range->to);
        $list = fn ($rows) => collect($rows)->map(fn (array $row) => ['name' => $row['name'], 'requests' => $result->count($row['count'])])->values()->all();

        $result->data = [
            'period' => $result->label($range->label),
            'from' => $result->day($range->from),
            'to' => $result->day($range->to),
            'top_services' => $list($report['topServices']),
            'top_packages' => $list($report['topPackages']),
            'orders' => $result->count($report['orderStatuses']['total']),
            'bookings' => $result->count($report['bookingStatuses']['total']),
        ];
        $result->link('Reports', route('owner.reports.index', array_filter(['from' => $range->from, 'to' => $range->to])));

        return $result;
    }
}
