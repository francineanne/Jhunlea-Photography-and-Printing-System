{{--
    Place once per layout. Fire with $dispatch('toast', { message, variant: 'success|warning|error|info', link: { label, url } }).
    Also shows the `status`, `warning` and `error` session flashes (and `status_link` = ['label' => ..., 'url' => ...]).
    Phase 10D: the ONE result message. At most three at once (the oldest gives way), about 5 seconds each (8 for a page
    flash), an error stays until it is closed, each is announced (role status, or alert for an error) and the motion is
    off with reduced motion.
--}}
@php
    $link = is_array(session('status_link')) && filled(session('status_link.url')) && filled(session('status_link.label'))
        ? ['label' => (string) session('status_link.label'), 'url' => (string) session('status_link.url')] : null;
    $flashes = array_values(array_filter([
        // Flashes stay a little longer than a button-triggered toast, because they confirm a whole page action
        session('status') ? ['message' => session('status'), 'variant' => 'success', 'timeout' => 8000, 'link' => $link] : null,
        session('warning') ? ['message' => session('warning'), 'variant' => 'warning', 'timeout' => 8000] : null,
        session('error') ? ['message' => session('error'), 'variant' => 'error'] : null,
    ]));
@endphp

<div x-data="uiToasts(@js($flashes))" x-on:toast.window="add($event.detail)"
    class="pointer-events-none fixed inset-x-4 top-4 z-[60] flex flex-col gap-2 sm:inset-x-auto sm:right-4 sm:w-96" data-toasts>
    {{-- Without JS the toast above never runs, so show the flashes as plain text instead --}}
    <noscript>
        @foreach ($flashes as $flash)
            <p role="{{ $flash['variant'] === 'error' ? 'alert' : 'status' }}" class="pointer-events-auto rounded-card border border-app-border bg-app-card p-4 text-sm shadow-lg">{{ $flash['message'] }}
                @if (! empty($flash['link']))
                    <a href="{{ $flash['link']['url'] }}" class="font-semibold text-brand underline">{{ $flash['link']['label'] }}</a>
                @endif
            </p>
        @endforeach
    </noscript>
    <template x-for="toast in items" :key="toast.id">
        <div x-show="toast.open" x-transition:enter="transition duration-[280ms] ease-brand"
            x-transition:enter-start="-translate-y-2 opacity-0 sm:translate-x-8 sm:translate-y-0"
            x-transition:enter-end="translate-x-0 translate-y-0 opacity-100"
            x-transition:leave="transition duration-150 ease-brand" x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0" x-bind:role="toast.variant === 'error' ? 'alert' : 'status'"
            x-bind:aria-live="toast.variant === 'error' ? 'assertive' : 'polite'"
            class="pointer-events-auto flex items-start gap-3 rounded-card border bg-app-card p-4 pr-2 text-sm shadow-lg"
            x-bind:class="{
                'border-status-green/40': toast.variant === 'success',
                'border-status-amber/50': toast.variant === 'warning',
                'border-status-red/40': toast.variant === 'error',
                'border-status-blue/40': toast.variant === 'info',
            }">
            <span class="material-symbols-outlined mt-px shrink-0 text-[20px]" aria-hidden="true"
                x-bind:class="{
                    'toast-check text-status-green': toast.variant === 'success',
                    'text-status-amber': toast.variant === 'warning',
                    'text-status-red': toast.variant === 'error',
                    'text-status-blue': toast.variant === 'info',
                }"
                x-text="{ success: 'check_circle', warning: 'warning', error: 'error', info: 'info' }[toast.variant] ?? 'info'"></span>
            <p class="min-w-0 flex-1 break-words py-px"><span x-text="toast.message"></span>
                <template x-if="toast.link">
                    <a x-bind:href="toast.link.url" class="ml-1 inline-flex min-h-6 items-center font-semibold text-brand underline underline-offset-2 focus-ring" x-text="toast.link.label"></a>
                </template>
            </p>
            <button type="button" x-on:click="dismiss(toast.id)" aria-label="Dismiss notification"
                class="-my-2 grid size-11 shrink-0 place-items-center rounded-control text-app-text-2 hover:text-app-text focus-ring">
                <span class="material-symbols-outlined text-[18px]" aria-hidden="true">close</span>
            </button>
        </div>
    </template>
</div>
