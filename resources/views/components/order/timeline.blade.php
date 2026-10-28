@props(['histories', 'customerId', 'viewer' => 'customer', 'newestFirst' => false])

{{--
    The history of a printing order, as in the order designs: a guideline, a marker per step and a card per row; the
    latest row is highlighted and its marker sends out a ring. Customers read "You" or "The shop", never another
    person's name or email; the owner reads names.
--}}
@php
    $rows = $newestFirst ? $histories->sortByDesc('id')->values() : $histories->sortBy('id')->values();
    $latestId = $histories->max('id');
@endphp

<ol {{ $attributes->class("relative space-y-4 pl-1 before:absolute before:bottom-6 before:left-[19px] before:top-4 before:w-0.5 before:bg-app-border before:content-['']") }}>
    @foreach ($rows as $row)
        @php
            $latest = $row->id === $latestId;
            $byCustomer = $row->changed_by === $customerId;
            $who = $viewer === 'customer'
                ? ($byCustomer ? 'You' : 'The shop')
                : ($byCustomer ? 'Customer' : 'Shop').($row->changedBy ? ' · '.$row->changedBy->name : '');
        @endphp
        <li class="relative flex items-start gap-4" @if ($latest) aria-current="step" @endif>
            <span @class([
                'relative z-10 grid size-8 shrink-0 place-items-center rounded-full shadow-sm',
                'bg-brand text-white shadow-[0_0_0_4px_rgb(198_40_40/0.15)]' => $latest,
                'bg-neutral-200 text-brand dark:bg-white/10' => ! $latest,
            ]) aria-hidden="true">
                @if ($latest)
                    <span class="absolute inline-flex size-full animate-ping rounded-full bg-brand opacity-30"></span>
                @endif
                <x-ui.icon :name="$row->event ? 'info' : ($row->to_status->value === 'rejected' || $row->to_status->value === 'cancelled' ? 'close' : 'check')" :size="16" class="relative" />
            </span>
            <div @class([
                'min-w-0 flex-1 rounded-card p-3 transition-colors sm:p-4',
                'bg-brand/5 ring-1 ring-brand/20' => $latest,
                'bg-neutral-100/60 hover:bg-neutral-100 dark:bg-white/5 dark:hover:bg-white/10' => ! $latest,
            ])>
                <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
                    <p class="font-semibold">{{ $row->title() }}</p>
                    <x-ui.date :value="$row->created_at" class="font-mono text-xs text-app-text-2" />
                </div>
                <p class="mt-0.5 text-xs text-app-text-2">{{ $who }}</p>
                @if ($row->note)
                    <p class="mt-2 whitespace-pre-line break-words text-sm text-app-text-2">{{ $row->note }}</p>
                @endif
            </div>
        </li>
    @endforeach
</ol>
