@props(['record', 'title' => 'What happens next'])

{{--
    Phase 10D: the short step timeline a customer reads after sending a request and on its detail page, driven by the
    real status (App\Support\NextSteps only words it). The current step is highlighted and marked aria-current; a closed
    request (rejected, cancelled, expired, unclaimed) shows one plain sentence instead.
--}}
@php
    $next = $record instanceof \App\Models\PrintingOrder
        ? app(\App\Support\NextSteps::class)->forOrder($record)
        : app(\App\Support\NextSteps::class)->forBooking($record);
@endphp

<section {{ $attributes->class('rounded-card border border-app-border bg-app-card p-4 sm:p-5') }} aria-labelledby="next-steps-title" data-next-steps>
    <h2 id="next-steps-title" class="flex items-center gap-2 font-semibold"><x-ui.icon name="route" :size="20" class="text-brand" />{{ $title }}</h2>

    @if ($next['closed'])
        <p class="mt-3 flex items-start gap-2 rounded-control bg-neutral-100 p-3 text-sm text-app-text-2 dark:bg-white/5"><x-ui.icon name="info" :size="18" class="mt-px shrink-0" />{{ $next['closed'] }}</p>
    @else
        <ol class="mt-4 space-y-0">
            @foreach ($next['steps'] as $step)
                <li class="relative flex gap-3 pb-4 last:pb-0" @if ($step['state'] === 'current') aria-current="step" @endif>
                    @unless ($loop->last)
                        <span @class(['absolute left-[13px] top-7 h-[calc(100%-1.75rem)] w-0.5', 'bg-brand' => $step['state'] === 'done', 'bg-app-border' => $step['state'] !== 'done']) aria-hidden="true"></span>
                    @endunless
                    <span @class([
                        'relative grid size-7 shrink-0 place-items-center rounded-full border-2 text-xs font-bold',
                        'border-brand bg-brand text-white' => $step['state'] === 'done',
                        'border-brand bg-app-card text-brand ring-4 ring-brand/15' => $step['state'] === 'current',
                        'border-app-border bg-app-card text-app-muted' => $step['state'] === 'upcoming',
                    ]) aria-hidden="true">
                        @if ($step['state'] === 'done')
                            <x-ui.icon name="check" :size="16" />
                        @else
                            {{ $loop->iteration }}
                        @endif
                    </span>
                    <div class="min-w-0 pt-0.5">
                        <p @class(['text-sm font-semibold', 'text-app-text-2' => $step['state'] === 'upcoming'])>{{ $step['label'] }}
                            <span class="sr-only">{{ ['done' => '(done)', 'current' => '(now)', 'upcoming' => '(later)'][$step['state']] }}</span>
                            @if ($step['state'] === 'current')
                                <span class="ml-1 rounded-full bg-brand/10 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-brand" aria-hidden="true">Now</span>
                            @endif
                        </p>
                        <p class="break-words text-sm text-app-text-2">{{ $step['text'] }}</p>
                    </div>
                </li>
            @endforeach
        </ol>
    @endif
</section>
