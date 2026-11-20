@props(['record'])

{{--
    Phase 10O: the ONE emphasized warning at the top of a customer's own order or booking page: what to do, by when,
    and what happens if not (App\Support\NextSteps::warning() words it from the real status; nothing here decides).
    Nothing is shown when nothing is urgent. Amber, in both themes; the live countdown sits next to a deadline.
--}}
@php($warning = app(\App\Support\NextSteps::class)->warning($record))

@if ($warning)
    <div {{ $attributes->class('flex items-start gap-3 rounded-card border border-status-amber/40 bg-status-amber-bg p-4 text-sm text-app-text sm:p-5') }} role="status" data-urgent-notice>
        <span class="grid size-9 shrink-0 place-items-center rounded-full bg-status-amber text-white" aria-hidden="true"><x-ui.icon name="priority_high" :size="20" /></span>
        <div class="min-w-0">
            <p class="text-[11px] font-bold uppercase tracking-wider text-status-amber">Action needed</p>
            <p class="mt-0.5 text-pretty font-semibold leading-snug">{{ $warning['text'] }}</p>
            @if ($warning['until'])
                <x-ui.countdown :until="$warning['until']" class="mt-1" />
            @endif
        </div>
    </div>
@endif
