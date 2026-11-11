<?php

namespace App\Services\Audit;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Support\LocalTime;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reads the audit trail for the owner's Activity log (Phase 8E): filtered rows, newest first, and which subjects still
 * exist (one query per kind of subject on the page, never one per row). Read only.
 */
class ActivityLogService
{
    /**
     * @param  array{group: ?string, from: ?string, to: ?string, search: string}  $filters
     * @return Builder<AuditLog>
     */
    public function query(array $filters): Builder
    {
        $search = $filters['search'];

        return AuditLog::query()
            ->when($filters['group'], fn (Builder $query, string $group) => $query->whereIn('action', array_map(fn (AuditAction $action) => $action->value, AuditAction::inGroup($group))))
            ->when($filters['from'], fn (Builder $query, string $day) => $query->where('occurred_at', '>=', LocalTime::dayStartUtc($day)))
            ->when($filters['to'], fn (Builder $query, string $day) => $query->where('occurred_at', '<=', LocalTime::dayEndUtc($day)))
            ->when($search !== '', function (Builder $query) use ($search) {
                // % _ \ typed by the owner are matched literally
                $like = '%'.addcslashes($search, '%_\\').'%';
                $actions = array_map(fn (AuditAction $action) => $action->value, array_filter(AuditAction::cases(), fn (AuditAction $action) => mb_stripos($action->label(), $search) !== false));

                $query->where(fn (Builder $inner) => $inner
                    ->where('subject_label', 'like', $like)
                    ->orWhere('summary', 'like', $like)
                    ->orWhere('actor_name', 'like', $like)
                    ->when($actions !== [], fn (Builder $q) => $q->orWhereIn('action', $actions)));
            });
    }

    /** @param array{group: ?string, from: ?string, to: ?string, search: string} $filters */
    public function page(array $filters): LengthAwarePaginator
    {
        return $this->query($filters)->orderByDesc('id')->paginate((int) config('audit.per_page'))->withQueryString();
    }

    /**
     * The links of the rows on a page whose subject still exists, keyed "type:id".
     *
     * @param  iterable<AuditLog>  $rows
     * @return array<string, string>
     */
    public function links(iterable $rows): array
    {
        $byType = collect($rows)->filter(fn (AuditLog $row) => $row->subject_type !== null && $row->subject_id !== null)
            ->groupBy('subject_type')->map(fn (Collection $group) => $group->pluck('subject_id')->unique()->values()->all());
        $links = [];

        foreach ($byType as $type => $ids) {
            $class = AuditSubject::TYPES[$type] ?? null;

            if ($class === null || AuditSubject::route($type, 1) === null) {
                continue;
            }

            foreach ((new $class)->newQuery()->whereKey($ids)->pluck((new $class)->getKeyName()) as $id) {
                $links[$type.':'.$id] = AuditSubject::route($type, (int) $id);
            }
        }

        return $links;
    }

    /** When the trail starts (the first row there is), in the shop's time, or null. */
    public function startedAt(): ?string
    {
        $first = DB::table('audit_logs')->orderBy('id')->value('occurred_at');

        return $first === null ? null : LocalTime::format($first, LocalTime::DATE);
    }
}
