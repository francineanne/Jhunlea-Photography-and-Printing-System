@props(['items', 'page', 'counts', 'unreadOnly', 'area'])

{{--
    The notifications page of either area (Phase 5B), after the notification designs: All and Unread tabs with counts,
    "Mark all as read", the items grouped Today and Earlier (the shop's calendar day), each with an icon, an unread dot
    that sends out a ring, the reference, the time and the sentence from the one message map. Opening an item is a
    POST (it marks it read and goes there); the reference is a plain link that goes without marking.
--}}
@php
    use App\Support\LocalTime;
    use Illuminate\Support\Carbon;

    $today = LocalTime::format(Carbon::now('UTC'), 'Y-m-d');
    $groups = $items->groupBy(fn ($item) => LocalTime::format($item['created_at'], 'Y-m-d') === $today ? 'Today' : 'Earlier');
    $tab = fn (bool $unread) => route($area.'.notifications.index', $unread ? ['tab' => 'unread'] : []);
@endphp

<div class="flex flex-wrap items-start justify-between gap-4">
    <div>
        <p class="flex items-center gap-2 font-mono text-[13px] font-medium uppercase text-brand"><span class="size-1.5 animate-pulse rounded-full bg-brand" aria-hidden="true"></span>{{ $area === 'owner' ? 'Owner console' : 'My account' }} / Notifications</p>
        <h1 class="mt-2 font-display text-3xl font-semibold tracking-tight sm:text-4xl">Notifications</h1>
        <p class="mt-2 max-w-2xl text-app-text-2">{{ $area === 'owner'
            ? 'New orders and photoshoot requests, and what customers did on them. Open one to work on it.'
            : 'Updates from the shop on your printing orders, photoshoots and payments. Open one to see the details.' }}</p>
    </div>
    @if ($counts['unread'] > 0)
        <form method="POST" action="{{ route($area.'.notifications.read-all') }}">
            @csrf
            <x-ui.button type="submit" variant="outline"><x-ui.icon name="done_all" :size="18" />Mark all as read</x-ui.button>
        </form>
    @endif
</div>

@if ($errors->any())
    <x-ui.notice class="mt-6 !border-status-red/30 !bg-status-red-bg">
        @foreach ($errors->all() as $message)<p>{{ $message }}</p>@endforeach
        <p>All notifications are shown.</p>
    </x-ui.notice>
@endif

<nav aria-label="Filter notifications" class="mt-6">
    <ul class="flex w-max gap-1 rounded-card bg-neutral-100 p-1 dark:bg-white/5">
        @foreach ([false => 'All', true => 'Unread'] as $unread => $label)
            @php($current = $unreadOnly === (bool) $unread)
            <li>
                <a href="{{ $tab((bool) $unread) }}" @if ($current) aria-current="page" @endif
                    @class(['inline-flex min-h-11 items-center gap-2 whitespace-nowrap rounded-xl px-4 text-sm font-semibold transition-colors focus-ring',
                        'bg-brand text-white shadow-sm' => $current,
                        'text-app-text-2 hover:text-app-text' => ! $current])>
                    {{ $label }}
                    <span @class(['rounded-full px-2 py-0.5 text-xs tabular-nums', 'bg-white/25' => $current, 'bg-black/10 dark:bg-white/15' => ! $current])>{{ $unread ? $counts['unread'] : $counts['all'] }}</span>
                </a>
            </li>
        @endforeach
    </ul>
</nav>

<div class="mt-6 max-w-3xl">
    @if ($items->isEmpty())
        <x-ui.card>
            <x-ui.empty-state :icon="$unreadOnly ? 'done_all' : 'notifications_off'"
                :title="$unreadOnly ? 'Nothing unread' : 'No notifications yet'"
                :description="$unreadOnly ? 'You have read everything.' : ($area === 'owner' ? 'New orders, requests and customer replies appear here.' : 'Updates on your orders, photoshoots and payments appear here.')" />
        </x-ui.card>
    @else
        @foreach ($groups as $group => $rows)
            <section class="mt-6 first:mt-0" aria-labelledby="group-{{ Str::slug($group) }}">
                <div class="mb-3 flex items-baseline justify-between gap-2">
                    <h2 id="group-{{ Str::slug($group) }}" class="flex items-center gap-2 text-lg font-semibold">
                        <span class="text-[11px] font-bold uppercase tracking-wider text-brand">{{ $group === 'Today' ? 'Latest' : 'Before today' }}</span><span aria-hidden="true">·</span>{{ $group }}
                    </h2>
                    <span class="font-mono text-xs text-app-text-2">{{ $rows->count() }} {{ Str::plural('item', $rows->count()) }}</span>
                </div>
                <ul class="space-y-3">
                    @foreach ($rows as $item)
                        <li @class(['group relative rounded-card border bg-app-card shadow-sm transition-colors dark:shadow-none',
                            'border-app-border border-l-4 border-l-brand' => ! $item['read'],
                            'border-app-border' => $item['read']])>
                            <form method="POST" action="{{ route($area.'.notifications.open', $item['id']) }}">
                                @csrf
                                <button type="submit" class="flex w-full items-start gap-4 rounded-card p-4 text-left transition-colors hover:bg-black/[0.02] focus-ring sm:p-5 dark:hover:bg-white/[0.03]">
                                    <span @class(['grid size-11 shrink-0 place-items-center rounded-xl transition-transform group-hover:scale-95',
                                        'bg-brand text-white' => ! $item['read'],
                                        'bg-neutral-100 text-app-text-2 dark:bg-white/10' => $item['read']]) aria-hidden="true">
                                        <x-ui.icon :name="$item['icon']" :size="22" />
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
                                            <span class="flex min-w-0 items-center gap-2">
                                                @unless ($item['read'])
                                                    <span class="relative flex size-2 shrink-0" aria-hidden="true">
                                                        <span class="absolute inline-flex size-full animate-ping rounded-full bg-brand opacity-60 motion-reduce:hidden"></span>
                                                        <span class="relative size-2 rounded-full bg-brand"></span>
                                                    </span>
                                                    <span class="sr-only">Unread:</span>
                                                @endunless
                                                <span @class(['break-words', 'font-semibold' => ! $item['read']])>{{ $item['title'] }}</span>
                                            </span>
                                            <x-ui.date :value="$item['created_at']" class="shrink-0 font-mono text-xs text-app-text-2" />
                                        </span>
                                        <span class="mt-1 block break-words text-sm text-app-text-2">{{ $item['message'] }}</span>
                                        <span class="mt-3 inline-flex items-center gap-1 text-sm font-semibold text-brand">Open<x-ui.icon name="arrow_forward" :size="16" class="transition-transform group-hover:translate-x-1" /></span>
                                    </span>
                                </button>
                            </form>
                            {{-- A plain link that goes without marking it read (for a new tab, for example) --}}
                            <a href="{{ route($area.'.notifications.go', $item['id']) }}" class="absolute bottom-3 right-3 inline-flex min-h-11 items-center gap-1 rounded-control px-2 font-mono text-xs font-semibold text-app-text-2 hover:text-brand hover:underline focus-ring sm:bottom-4 sm:right-4"
                                aria-label="{{ $item['reference'] }}, open without marking as read">{{ $item['reference'] }}<x-ui.icon name="open_in_new" :size="14" /></a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach

        <div class="mt-6">{{ $page->links('pagination.simple') }}</div>
    @endif
</div>
