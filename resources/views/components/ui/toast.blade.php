{{-- Place once per layout. Fire with $dispatch('toast', { message, variant: 'success|error|info' }). Also shows the `status` and `error` session flashes. --}}
@php
    $flashes = array_values(array_filter([
        // Flashes stay a little longer than a button-triggered toast, because they confirm a whole page action
        session('status') ? ['message' => session('status'), 'variant' => 'success', 'timeout' => 8000] : null,
        session('error') ? ['message' => session('error'), 'variant' => 'error', 'timeout' => 8000] : null,
    ]));
@endphp

<div x-data="uiToasts(@js($flashes))" x-on:toast.window="add($event.detail)" aria-live="polite"
    class="pointer-events-none fixed inset-x-4 top-4 z-[60] flex flex-col gap-2 sm:inset-x-auto sm:right-4 sm:w-96">
    {{-- Without JS the toast above never runs, so show the flashes as plain text instead --}}
    <noscript>
        @foreach ($flashes as $flash)
            <p role="{{ $flash['variant'] === 'error' ? 'alert' : 'status' }}" class="pointer-events-auto rounded-card border border-app-border bg-app-card p-4 text-sm shadow-lg">{{ $flash['message'] }}</p>
        @endforeach
    </noscript>
    <template x-for="toast in items" :key="toast.id">
        <div x-show="toast.open" x-transition:enter="transition duration-[280ms] ease-brand"
            x-transition:enter-start="-translate-y-2 opacity-0 sm:translate-x-8 sm:translate-y-0"
            x-transition:enter-end="translate-x-0 translate-y-0 opacity-100"
            x-transition:leave="transition duration-150 ease-brand" x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0" x-bind:role="toast.variant === 'error' ? 'alert' : 'status'"
            class="pointer-events-auto flex items-start gap-3 rounded-card border bg-app-card p-4 pr-2 text-sm shadow-lg"
            x-bind:class="{
                'border-status-green/40': toast.variant === 'success',
                'border-status-red/40': toast.variant === 'error',
                'border-status-blue/40': toast.variant === 'info',
            }">
            <span class="material-symbols-outlined mt-px shrink-0 text-[20px]" aria-hidden="true"
                x-bind:class="{
                    'text-status-green': toast.variant === 'success',
                    'text-status-red': toast.variant === 'error',
                    'text-status-blue': toast.variant === 'info',
                }"
                x-text="{ success: 'check_circle', error: 'error', info: 'info' }[toast.variant] ?? 'info'"></span>
            <p class="min-w-0 flex-1 py-px" x-text="toast.message"></p>
            <button type="button" x-on:click="dismiss(toast.id)" aria-label="Dismiss notification"
                class="-my-2 grid size-11 shrink-0 place-items-center rounded-control text-app-text-2 hover:text-app-text focus-ring">
                <span class="material-symbols-outlined text-[18px]" aria-hidden="true">close</span>
            </button>
        </div>
    </template>
</div>
