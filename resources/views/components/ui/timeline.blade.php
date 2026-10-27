@props(['events' => []])

{{-- Each event: title (required), description, at, by. Only the events passed in are shown. --}}
@if (count($events))
    <ol {{ $attributes->class('relative') }}>
        @foreach ($events as $index => $event)
            @php $latest = $loop->last; @endphp
            <li class="relative flex gap-4 pb-6 last:pb-0">
                @unless ($latest)
                    <span class="absolute -bottom-3 left-[11px] top-3 w-0.5 origin-top animate-draw bg-app-border" style="animation-delay: {{ $index * 150 }}ms" aria-hidden="true"></span>
                @endunless

                <span @class([
                    'relative z-10 mt-0.5 grid size-6 shrink-0 place-items-center rounded-full border-2',
                    'border-brand bg-brand' => $latest,
                    'border-app-border bg-app-card text-app-text-2' => ! $latest,
                ]) aria-hidden="true">
                    @if ($latest)
                        <span class="size-2 rounded-full bg-white"></span>
                    @else
                        <x-ui.icon name="check" :size="14" />
                    @endif
                </span>

                <div class="min-w-0 flex-1">
                    <p class="font-semibold">{{ $event['title'] }}</p>
                    @if (! empty($event['description']))
                        <p class="mt-0.5 text-sm text-app-text-2">{{ $event['description'] }}</p>
                    @endif
                    @if (! empty($event['at']) || ! empty($event['by']))
                        <p class="mt-1 font-mono text-xs text-app-text-2">{{ collect([$event['at'] ?? null, $event['by'] ?? null])->filter()->implode(' · ') }}</p>
                    @endif
                </div>
            </li>
        @endforeach
    </ol>
@endif
