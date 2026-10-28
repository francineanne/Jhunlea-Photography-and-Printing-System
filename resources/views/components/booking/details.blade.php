@props(['booking'])

{{--
    What the customer asked for, as saved with the request: the package as it was then (snapshot), people, contact,
    backdrop or own idea, prints preference and notes. The same for the customer and the owner; it never shows the
    studio's internal notes. Every value is escaped.
--}}
@php
    $package = $booking->snapshot['package'] ?? [];
    $themeName = $booking->snapshot['theme']['name'] ?? null;
    $inclusions = $package['inclusions'] ?? [];
@endphp

<div {{ $attributes->class('space-y-4') }}>
    <dl class="grid gap-3 sm:grid-cols-3 [&>div]:min-w-0">
        <div class="rounded-card bg-neutral-100 p-4 dark:bg-white/5">
            <dt class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Package</dt>
            <dd class="mt-1 break-words font-semibold">{{ $package['name'] ?? $booking->package?->name }}</dd>
            <dd class="mt-0.5 text-xs text-app-text-2">{{ $package['duration_minutes'] ?? $booking->durationMinutes() }} mins
                @if (! empty($package['digital_outputs']))· {{ $package['digital_outputs'] }} edited {{ (int) $package['digital_outputs'] === 1 ? 'photo' : 'photos' }}@endif
            </dd>
        </div>
        <div class="rounded-card bg-neutral-100 p-4 dark:bg-white/5">
            <dt class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Backdrop</dt>
            <dd class="mt-1 break-words font-semibold">{{ $themeName ?? 'No listed backdrop' }}</dd>
            @if ($booking->custom_theme_text)
                <dd class="mt-0.5 whitespace-pre-line break-words text-xs text-app-text-2">{{ $booking->custom_theme_text }}</dd>
            @endif
        </div>
        <div class="rounded-card bg-neutral-100 p-4 dark:bg-white/5">
            <dt class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">People and prints</dt>
            <dd class="mt-1 font-semibold tabular-nums">{{ $booking->group_size }} {{ $booking->group_size === 1 ? 'person' : 'people' }}</dd>
            <dd class="mt-0.5 text-xs text-app-text-2">{{ $booking->wants_prints ? 'May want prints after the shoot' : 'Digital only' }}</dd>
        </div>
    </dl>

    <dl class="grid gap-3 text-sm sm:grid-cols-2 [&>div]:min-w-0">
        <div>
            <dt class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Contact number</dt>
            <dd class="mt-0.5 break-words font-mono">{{ $booking->contact_number }}</dd>
        </div>
        @if ($inclusions !== [])
            <div>
                <dt class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Included</dt>
                <dd class="mt-0.5">
                    <ul class="space-y-0.5">
                        @foreach ($inclusions as $item)
                            <li class="flex gap-1.5 break-words"><x-ui.icon name="check" :size="16" class="mt-0.5 shrink-0 text-status-green" />{{ $item }}</li>
                        @endforeach
                    </ul>
                </dd>
            </div>
        @endif
    </dl>

    <figure class="rounded-card border border-app-border p-4">
        <figcaption class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider text-brand"><x-ui.icon name="chat" :size="16" />Notes from the customer</figcaption>
        @if ($booking->notes)
            <blockquote class="mt-2 whitespace-pre-line break-words font-display text-lg italic leading-relaxed">{{ $booking->notes }}</blockquote>
        @else
            <p class="mt-2 text-sm text-app-text-2">No notes.</p>
        @endif
    </figure>
</div>
