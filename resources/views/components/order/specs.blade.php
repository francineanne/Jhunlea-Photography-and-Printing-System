@props(['order', 'viewer' => 'customer'])

{{-- What was ordered, from the snapshot taken when the order was placed (the catalog may have changed since). --}}
<div {{ $attributes->class('space-y-4') }}>
    <dl class="grid gap-3 sm:grid-cols-2">
        <div class="rounded-card bg-neutral-100 p-4 dark:bg-white/5">
            <dt class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider text-app-text-2"><x-ui.icon name="content_copy" :size="16" />Copies</dt>
            <dd class="mt-1 font-semibold tabular-nums">{{ $order->quantity }}</dd>
        </div>
        @if ($order->pages() !== null)
            <div class="rounded-card bg-neutral-100 p-4 dark:bg-white/5">
                <dt class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider text-app-text-2"><x-ui.icon name="description" :size="16" />Pages</dt>
                <dd class="mt-1 font-semibold tabular-nums">{{ $order->pages() }}</dd>
            </div>
        @endif
        @foreach ($order->chosenOptions() as $option)
            <div class="rounded-card bg-neutral-100 p-4 dark:bg-white/5">
                <dt class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider text-app-text-2"><x-ui.icon name="tune" :size="16" />{{ $option['group'] }}</dt>
                <dd class="mt-1 break-words font-semibold">{{ $option['label'] }}</dd>
                <dd class="font-mono text-xs text-app-text-2">{{ $option['price'] }}</dd>
            </div>
        @endforeach
    </dl>

    @if ($order->notes)
        <div class="flex gap-3 rounded-card bg-neutral-100 p-4 dark:bg-white/5">
            <x-ui.icon name="format_quote" :size="22" class="shrink-0 text-brand" />
            <div class="min-w-0">
                <p class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">{{ $viewer === 'customer' ? 'Your special instructions' : 'Customer instructions' }}</p>
                <p class="mt-1 whitespace-pre-line break-words text-sm italic">{{ $order->notes }}</p>
            </div>
        </div>
    @endif
</div>
