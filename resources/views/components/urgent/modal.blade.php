@props(['urgent'])

{{--
    Phase 10O: "Needs your attention", shown ONCE after signing in, on the first page, only when something is urgent
    (App\Services\UrgentItems::onceForLogin(), a session flag; no database). The one modal component: focus trapped,
    Esc, the close button, the backdrop and "Got it" close it. Each line: one sentence, its deadline in Manila time and
    a link to the record; a walk-in's phone to call. "+N more" opens the list the rest belong to.
--}}
<x-ui.modal name="urgent" title="Needs your attention" data-urgent-modal>
    <ul class="space-y-3">
        @foreach ($urgent['items'] as $item)
            <li class="rounded-control border border-status-amber/40 bg-status-amber-bg p-3" data-urgent-item>
                {{-- The sentence is escaped first; only then is each PR-/PB- reference kept on one line --}}
                <p class="text-pretty font-semibold leading-snug text-app-text">{!! preg_replace('/\b(P[RB]-\d+)/', '<span class="whitespace-nowrap">$1</span>', e($item['text'])) !!}</p>
                <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-app-text-2">
                    @if ($item['when'])
                        <span class="inline-flex items-center gap-1 tabular-nums"><x-ui.icon name="schedule" :size="14" />{{ \App\Support\LocalTime::format($item['when']) }}</span>
                    @endif
                    @if ($item['phone'])
                        <a href="{{ $item['phone']['href'] }}" class="inline-flex min-h-11 items-center gap-1 rounded font-semibold text-brand tabular-nums hover:underline focus-ring sm:min-h-0 dark:text-[#ffb4ac]"><x-ui.icon name="call" :size="14" />Call {{ $item['phone']['text'] }}</a>
                    @endif
                    <a href="{{ $item['url'] }}" class="ml-auto inline-flex min-h-11 items-center gap-1 rounded font-semibold text-brand hover:underline focus-ring sm:min-h-0 dark:text-[#ffb4ac]">Open<x-ui.icon name="arrow_forward" :size="14" /></a>
                </p>
            </li>
        @endforeach
    </ul>
    @if ($urgent['more'] > 0 && $urgent['moreUrl'])
        <p class="mt-3 text-center"><a href="{{ $urgent['moreUrl'] }}" class="inline-flex min-h-11 items-center rounded font-semibold text-brand hover:underline focus-ring dark:text-[#ffb4ac]">+{{ $urgent['more'] }} more</a></p>
    @endif

    <x-slot:footer>
        <x-ui.button type="button" x-on:click="$dispatch('close-modal')" data-urgent-close>Got it</x-ui.button>
    </x-slot:footer>
</x-ui.modal>
{{-- Opens itself once the page is ready; without JavaScript the list simply is not shown --}}
<div x-init="$nextTick(() => $dispatch('open-modal', 'urgent'))" hidden></div>
