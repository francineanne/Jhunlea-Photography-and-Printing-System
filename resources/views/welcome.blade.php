@extends('layouts.app')

@section('title', 'Phase 0 style check')

@section('content')
    <main class="mx-auto max-w-5xl px-4 py-10">
        <p class="mb-6 rounded-control border border-dashed border-brand px-4 py-2 text-sm text-brand">
            Phase 0 style check: temporary page. Phase 1 will replace it.
        </p>

        <header class="flex items-center justify-between">
            <span class="font-display text-2xl font-semibold tracking-wide">JHUNLEA</span>
            <button type="button" data-theme-toggle aria-label="Toggle light and dark theme"
                class="inline-flex h-11 w-11 items-center justify-center rounded-control border border-app-border bg-app-card">
                <span class="material-symbols-outlined">contrast</span>
            </button>
        </header>

        <section class="mt-10 rounded-card border border-app-border bg-app-card p-6">
            <h1 class="font-display text-4xl font-semibold">Photography &amp; Printing</h1>
            <p class="mt-3 text-text-secondary dark:text-text-muted">
                Body text in Plus Jakarta Sans. Reference number:
                <span class="font-mono text-sm font-medium">PR-0012</span>
            </p>

            <div class="mt-6 flex items-center gap-4">
                <button type="button"
                    class="inline-flex h-11 items-center gap-2 rounded-control bg-brand px-5 font-semibold text-white hover:bg-brand-hover">
                    <span class="material-symbols-outlined">photo_camera</span>
                    Sample button
                </button>
            </div>

            <div class="mt-6 flex flex-wrap gap-2">
                <span class="rounded-full bg-status-amber-bg px-3 py-1 text-xs font-semibold text-status-amber">Under review</span>
                <span class="rounded-full bg-status-blue-bg px-3 py-1 text-xs font-semibold text-status-blue">Awaiting schedule</span>
                <span class="rounded-full bg-status-violet-bg px-3 py-1 text-xs font-semibold text-status-violet">Processing</span>
                <span class="rounded-full bg-status-green-bg px-3 py-1 text-xs font-semibold text-status-green">Ready</span>
                <span class="rounded-full bg-status-gray-bg px-3 py-1 text-xs font-semibold text-status-gray">Completed</span>
                <span class="rounded-full bg-status-red-bg px-3 py-1 text-xs font-semibold text-status-red">Rejected</span>
            </div>
        </section>
    </main>
@endsection
