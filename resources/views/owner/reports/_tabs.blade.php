{{-- The two owner reports under the one Reports sidebar item (Phase 8C): Income (the default) and Requests (Phase 6A) --}}
<nav aria-label="Reports" class="mt-6 flex gap-1 rounded-control border border-app-border bg-app-card p-1 sm:inline-flex print:hidden">
    @foreach ([['owner.reports.income', 'Income', 'payments'], ['owner.reports.index', 'Requests', 'receipt_long']] as [$name, $label, $icon])
        @php($here = request()->routeIs($name))
        <a href="{{ route($name) }}" @if ($here) aria-current="page" @endif
            @class(['inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-[8px] px-4 text-sm font-semibold transition-colors focus-ring sm:flex-none',
                'bg-ink text-white dark:bg-white dark:text-ink' => $here,
                'text-app-text-2 hover:bg-black/5 hover:text-app-text dark:hover:bg-white/10' => ! $here])><x-ui.icon :name="$icon" :size="18" />{{ $label }}</a>
    @endforeach
</nav>
