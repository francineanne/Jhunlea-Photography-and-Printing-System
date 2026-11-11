@extends('layouts.owner')

@section('title', 'Activity log')

{{--
    The owner's Activity log (Phase 8E): the audit trail, newest first. Each row is one sentence written by code when the
    action happened (who, what, which record), with the time in Manila and a link to the record while it still exists.
    Read only: nothing on this page edits or deletes a row. The integrity line comes from the hash chain.
--}}
@php
    use App\Services\Audit\AuditSubject;
    use App\Support\LocalTime;

    $chainOk = $quick['ok'] && ($full === null || $full['ok']);
    $brokenId = ! $quick['ok'] ? $quick['broken_id'] : ($full['broken_id'] ?? null);
    $here = fn (array $query) => route('owner.activity.index', array_filter($query + ['group' => $filters['group'], 'q' => $filters['search'] ?: null, 'range' => $filters['range'] === 'custom' ? null : $filters['range']], fn ($value) => $value !== null));
    $csvQuery = array_filter(['group' => $filters['group'], 'q' => $filters['search'] ?: null, 'range' => $filters['range'] === 'custom' ? null : $filters['range'],
        'from' => $filters['range'] === 'custom' ? $filters['from'] : null, 'to' => $filters['range'] === 'custom' ? $filters['to'] : null]);
    $sentence = fn ($row) => $row->actor_role === 'system' ? $row->summary : $row->actor_name.' '.lcfirst($row->summary);
@endphp

@section('main')
    <div class="flex flex-wrap items-end justify-between gap-6">
        <div class="min-w-0 xl:max-w-2xl">
            <nav aria-label="Breadcrumb" class="text-xs font-semibold uppercase tracking-wider text-app-text-2">
                <a href="{{ route('owner.settings.index') }}" class="inline-flex min-h-11 items-center rounded hover:text-brand focus-ring">Settings</a> <span aria-hidden="true">/</span> Activity log
            </nav>
            <h1 class="mt-2 font-display text-3xl font-semibold tracking-tight sm:text-4xl">Activity log</h1>
            <p class="mt-2 text-app-text-2">Every important action in the shop's system: payments, prices, statuses, walk-ins, settings, sign-ins and exports. Rows are written when the action happens and are never edited or deleted.</p>
        </div>
        <x-ui.button :href="route('owner.activity.csv', $csvQuery)"><x-ui.icon name="download" :size="18" />Export CSV</x-ui.button>
    </div>

    {{-- The integrity line: the newest rows are checked on every visit, the whole chain by `php artisan audit:verify` --}}
    @if ($chainOk)
        <p class="mt-6 flex flex-wrap items-center gap-2 rounded-control border border-status-green/30 bg-status-green-bg/50 px-4 py-3 text-sm" role="status">
            <x-ui.icon name="verified" :size="18" class="text-status-green" />
            <span><span class="font-semibold">Last check: chain intact.</span> The newest {{ $quick['checked'] }} {{ Str::plural('row', $quick['checked']) }} were checked just now{{ $full ? '; every row was checked on '.LocalTime::format($full['at']).' ('.$full['checked'].' '.Str::plural('row', $full['checked']).')' : '' }}.</span>
        </p>
    @else
        <div class="mt-6 flex items-start gap-3 rounded-control border border-status-red/40 bg-status-red-bg p-4 text-sm text-status-red" role="alert">
            <x-ui.icon name="gpp_bad" :size="20" class="mt-px shrink-0" />
            <div>
                <p class="font-semibold">The activity log does not match its own check{{ $brokenId ? ' at row '.$brokenId : '' }}.</p>
                <p class="mt-1">{{ ! $quick['ok'] ? ($quick['message'] ?? '') : ($full['message'] ?? '') }} A row was changed, removed or moved outside the system. Nothing here was changed by the app itself. Ask the developer to run <span class="font-mono">php artisan audit:verify</span> and to look at the database backups.</p>
            </div>
        </div>
    @endif

    @if ($errors->any())
        <x-ui.notice class="mt-4 !border-status-red/30 !bg-status-red-bg">
            @foreach ($errors->all() as $message)<p>{{ $message }}</p>@endforeach
        </x-ui.notice>
    @endif

    {{-- Filters: the kind of action, the period, and a search --}}
    <section class="mt-6 rounded-card border border-app-border bg-app-card p-4 shadow-sm dark:shadow-none" aria-label="Filter the activity">
        <nav aria-label="Kind of activity" class="flex flex-wrap gap-2">
            <a href="{{ $here(['group' => null]) }}" @if ($filters['group'] === null) aria-current="page" @endif
                @class(['inline-flex min-h-11 items-center rounded-full border px-4 text-sm font-semibold transition-colors focus-ring',
                    'border-brand bg-brand text-white' => $filters['group'] === null, 'border-app-border text-app-text-2 hover:border-brand hover:text-brand' => $filters['group'] !== null])>All</a>
            @foreach ($groups as $key => $label)
                <a href="{{ $here(['group' => $key]) }}" @if ($filters['group'] === $key) aria-current="page" @endif
                    @class(['inline-flex min-h-11 items-center whitespace-nowrap rounded-full border px-4 text-sm font-semibold transition-colors focus-ring',
                        'border-brand bg-brand text-white' => $filters['group'] === $key, 'border-app-border text-app-text-2 hover:border-brand hover:text-brand' => $filters['group'] !== $key])>{{ $label }}</a>
            @endforeach
        </nav>

        <nav aria-label="Period" class="mt-4 flex flex-wrap gap-1 rounded-control bg-neutral-100 p-1 sm:inline-flex dark:bg-white/5">
            @foreach ($ranges as $key => $label)
                @php($current = $filters['range'] === $key)
                <a href="{{ $here(['range' => $key]) }}" @if ($current) aria-current="page" @endif
                    @class(['inline-flex min-h-11 flex-1 items-center justify-center whitespace-nowrap rounded-[8px] px-4 text-sm font-semibold transition-colors focus-ring sm:flex-none',
                        'bg-app-card text-app-text shadow-sm dark:bg-white/15' => $current, 'text-app-text-2 hover:text-app-text' => ! $current])>{{ $label }}</a>
            @endforeach
        </nav>

        <form method="GET" action="{{ route('owner.activity.index') }}" role="search" class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-[2fr_1fr_1fr_auto] lg:items-end">
            @if ($filters['group'])<input type="hidden" name="group" value="{{ $filters['group'] }}">@endif
            <x-ui.input label="Search" name="q" type="search" icon="search" :value="$filters['search']" maxlength="60" placeholder="PR-, PB-, PAY- number, name or action" />
            <x-ui.input label="From" name="from" type="date" :value="$filters['range'] === 'custom' ? $filters['from'] : null" />
            <x-ui.input label="To" name="to" type="date" :value="$filters['range'] === 'custom' ? $filters['to'] : null" />
            <x-ui.button type="submit" variant="outline">Show</x-ui.button>
        </form>
        <p class="mt-3 text-xs text-app-text-2">
            {{ $startedAt ? 'Activity is recorded from '.$startedAt.'.' : 'Nothing has been recorded yet.' }} Times are the shop's time (Philippine time). Earlier actions are not in this log.
        </p>
    </section>

    <section class="mt-6" aria-labelledby="rows-title">
        <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
            <h2 id="rows-title" class="flex items-center gap-2 font-display text-xl font-semibold"><x-ui.icon name="history" :size="22" class="text-brand" />Activity</h2>
            <span class="font-mono text-xs text-app-text-2">{{ $rows->total() }} {{ Str::plural('entry', $rows->total()) }} · newest first</span>
        </div>

        @if ($rows->isEmpty())
            <x-ui.card>
                <x-ui.empty-state icon="history_toggle_off" title="No activity found" description="Nothing matches this period, kind or search. Try another period or clear the search.">
                    <x-ui.button :href="route('owner.activity.index', ['range' => 'all'])" variant="outline">Show all activity</x-ui.button>
                </x-ui.empty-state>
            </x-ui.card>
        @else
            <ol class="divide-y divide-app-border overflow-hidden rounded-card border border-app-border bg-app-card shadow-[0_1px_2px_rgba(0,0,0,0.04)] dark:shadow-none">
                @foreach ($rows as $row)
                    @php($link = $row->subject_type ? ($links[$row->subject_type.':'.$row->subject_id] ?? null) : null)
                    <li class="flex flex-col gap-2 p-4 sm:flex-row sm:items-start sm:gap-4">
                        <time datetime="{{ LocalTime::iso($row->occurred_at) }}" class="shrink-0 font-mono text-xs text-app-text-2 sm:w-40 sm:pt-0.5">{{ LocalTime::format($row->occurred_at, 'M j, Y g:i:s A') }}</time>
                        <div class="min-w-0 flex-1">
                            <p class="break-words text-sm">{{ $sentence($row) }}</p>
                            <p class="mt-1.5 flex flex-wrap items-center gap-2 text-xs text-app-text-2">
                                <span class="rounded-full bg-neutral-100 px-2 py-0.5 font-semibold dark:bg-white/10">{{ $row->action->label() }}</span>
                                <span>{{ ucfirst($row->actor_role) }}</span>
                                @if ($row->subject_label)
                                    @if ($link)
                                        <a href="{{ $link }}" class="inline-flex min-h-11 items-center sm:min-h-9 gap-1 rounded font-mono font-semibold text-brand hover:underline focus-ring">{{ $row->subject_label }}<x-ui.icon name="arrow_forward" :size="14" /></a>
                                    @else
                                        <span class="font-mono">{{ $row->subject_label }}</span>
                                    @endif
                                @endif
                                @if ($row->ip)<span class="font-mono">IP {{ $row->ip }}</span>@endif
                            </p>
                        </div>
                    </li>
                @endforeach
            </ol>
            <div class="mt-6">{{ $rows->links('pagination.simple') }}</div>
        @endif
    </section>
@endsection
