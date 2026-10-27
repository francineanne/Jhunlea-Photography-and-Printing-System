{{-- The click handler is bound in app.js through the data-theme-toggle attribute --}}
<button type="button" data-theme-toggle aria-label="Toggle light and dark theme"
    {{ $attributes->class('grid size-11 shrink-0 place-items-center rounded-control border border-app-border bg-app-card text-app-text focus-ring') }}>
    <x-ui.icon name="contrast" />
</button>
