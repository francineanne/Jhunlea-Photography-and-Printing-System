<?php

namespace App\Services\Reports;

use App\Models\User;

/** The income report view each owner last chose (Phase 8C): daily, weekly or monthly. The only writer of report_view. */
class ReportViewPreference
{
    public function for(User $owner): string
    {
        return in_array($owner->report_view, IncomePeriod::REMEMBERED, true) ? $owner->report_view : 'daily';
    }

    public function remember(User $owner, string $view): void
    {
        if (in_array($view, IncomePeriod::REMEMBERED, true) && $owner->report_view !== $view) {
            $owner->forceFill(['report_view' => $view])->save();
        }
    }
}
