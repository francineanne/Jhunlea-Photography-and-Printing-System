{{-- Our own pagination view: the framework's one uses classes outside the folders Tailwind scans. Use ->links('pagination.simple'). --}}
@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination" class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-app-text-2">
            Showing <span class="font-semibold text-app-text">{{ $paginator->firstItem() }}</span>
            to <span class="font-semibold text-app-text">{{ $paginator->lastItem() }}</span>
            of <span class="font-semibold text-app-text">{{ $paginator->total() }}</span>
        </p>

        <div class="flex items-center gap-2">
            @if ($paginator->onFirstPage())
                <x-ui.button variant="outline" :disabled="true"><x-ui.icon name="chevron_left" :size="18" /> Previous</x-ui.button>
            @else
                <x-ui.button :href="$paginator->previousPageUrl()" variant="outline" rel="prev"><x-ui.icon name="chevron_left" :size="18" /> Previous</x-ui.button>
            @endif

            <span class="px-1 text-sm tabular-nums text-app-text-2">Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}</span>

            @if ($paginator->hasMorePages())
                <x-ui.button :href="$paginator->nextPageUrl()" variant="outline" rel="next">Next <x-ui.icon name="chevron_right" :size="18" /></x-ui.button>
            @else
                <x-ui.button variant="outline" :disabled="true">Next <x-ui.icon name="chevron_right" :size="18" /></x-ui.button>
            @endif
        </div>
    </nav>
@endif
