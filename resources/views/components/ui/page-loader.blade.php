{{-- Visible from first paint, faded out by Alpine shortly after window load. Skipped with reduced motion (see app.css). --}}
{{-- The fade is plain CSS, so a throttled background tab can never leave the overlay stuck on screen. --}}
<noscript><style>.page-loader { display: none !important; }</style></noscript>

<div x-data="uiPageLoader" x-on:replay-loader.window="replay()" x-bind:aria-hidden="!show"
    x-bind:class="show || 'pointer-events-none invisible opacity-0'"
    class="page-loader fixed inset-0 z-[100] grid place-items-center bg-app-bg transition-[opacity,visibility] duration-[280ms] ease-brand"
    role="status" aria-label="Loading page">
    <div class="flex flex-col items-center gap-6">
        <svg class="animate-aperture-open text-brand" width="72" height="72" viewBox="0 0 24 24" fill="none"
            stroke="currentColor" stroke-width="1.25" stroke-linecap="round" aria-hidden="true">
            <circle cx="12" cy="12" r="10" />
            @foreach ([0, 60, 120, 180, 240, 300] as $angle)
                <path d="M22 12L4.34 18.43" transform="rotate({{ $angle }} 12 12)" />
            @endforeach
        </svg>
        <x-ui.logo class="animate-fade-up" />
    </div>
</div>
