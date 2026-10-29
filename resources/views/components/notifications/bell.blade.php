@props(['bell', 'area'])

{{--
    The header bell (Phase 5B), as in the notification designs: an unread badge whose dot sends out a ring, and a
    dropdown with the latest few, "Mark all as read" and "See all". Without scripts the bell is a plain link to the
    notifications page. On a phone the panel spans the screen (inset 16px), so it never leaves a 360px screen.
--}}
@php
    $unread = (int) $bell['unread'];
    $badge = $unread > \App\Services\NotificationFeed::BADGE_MAX ? \App\Services\NotificationFeed::BADGE_MAX.'+' : (string) $unread;
    $index = route($area.'.notifications.index');
@endphp

<div class="relative" x-data="{ open: false }" x-on:keydown.escape.window="open = false" x-on:click.outside="open = false">
    <a href="{{ $index }}" x-on:click.prevent="open = ! open" x-bind:aria-expanded="open" aria-haspopup="true"
        aria-label="Notifications{{ $unread > 0 ? ', '.$badge.' unread' : '' }}"
        class="relative grid size-11 shrink-0 place-items-center rounded-control border border-app-border bg-app-card transition-colors hover:bg-black/5 focus-ring dark:hover:bg-white/10">
        <x-ui.icon name="notifications" />
        @if ($unread > 0)
            <span class="absolute -right-1.5 -top-1.5 flex min-w-5 items-center justify-center" aria-hidden="true">
                <span class="absolute inline-flex size-full animate-ping rounded-full bg-brand opacity-40 motion-reduce:hidden"></span>
                <span class="relative rounded-full bg-brand px-1.5 py-px text-[11px] font-bold leading-4 tabular-nums text-white ring-2 ring-app-bg">{{ $badge }}</span>
            </span>
        @endif
    </a>

    <div x-show="open" x-cloak
        x-transition:enter="transition duration-200 ease-brand" x-transition:enter-start="-translate-y-1 opacity-0" x-transition:enter-end="translate-y-0 opacity-100"
        x-transition:leave="transition duration-150 ease-brand" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
        class="fixed inset-x-4 top-[4.5rem] z-50 flex max-h-[calc(100dvh-6rem)] flex-col overflow-hidden rounded-card border border-app-border bg-app-card shadow-[0_24px_48px_-12px_rgba(23,23,23,0.18)] sm:absolute sm:inset-x-auto sm:right-0 sm:top-full sm:mt-2 sm:w-96 dark:shadow-2xl"
        role="dialog" aria-label="Latest notifications">
        <div class="flex items-center justify-between gap-2 border-b border-app-border px-4 py-3">
            <p class="flex min-w-0 items-center gap-2 font-semibold">
                <x-ui.icon name="notifications" :size="20" class="shrink-0 text-brand" />Notifications
                @if ($unread > 0)
                    <span class="whitespace-nowrap rounded-full bg-status-red-bg px-2 py-0.5 font-mono text-[11px] font-bold text-brand">{{ $badge }} unread</span>
                @endif
            </p>
            @if ($unread > 0)
                <form method="POST" action="{{ route($area.'.notifications.read-all') }}">
                    @csrf
                    <button type="submit" aria-label="Mark all as read" class="inline-flex min-h-11 shrink-0 items-center whitespace-nowrap rounded px-2 font-mono text-xs font-semibold text-brand hover:underline focus-ring">Mark read</button>
                </form>
            @endif
        </div>

        @if ($bell['items']->isEmpty())
            <div class="flex flex-col items-center px-6 py-10 text-center">
                <span class="grid size-12 place-items-center rounded-full bg-neutral-100 text-app-text-2 dark:bg-white/5"><x-ui.icon name="notifications_off" /></span>
                <p class="mt-3 font-semibold">No notifications yet</p>
                <p class="mt-1 text-sm text-app-text-2">{{ $area === 'owner' ? 'New orders, requests and customer replies appear here.' : 'Updates on your orders, photoshoots and payments appear here.' }}</p>
            </div>
        @else
            <ul class="min-h-0 space-y-1 overflow-y-auto p-2">
                @foreach ($bell['items'] as $item)
                    <li>
                        <form method="POST" action="{{ route($area.'.notifications.open', $item['id']) }}">
                            @csrf
                            <button type="submit" @class([
                                'flex w-full items-start gap-3 rounded-control p-3 text-left text-sm transition-colors focus-ring',
                                'bg-neutral-100 hover:bg-neutral-200 dark:bg-white/10 dark:hover:bg-white/15' => ! $item['read'],
                                'hover:bg-black/5 dark:hover:bg-white/5' => $item['read'],
                            ])>
                                <span @class(['mt-1.5 size-2 shrink-0 rounded-full', 'bg-brand' => ! $item['read'], 'bg-transparent' => $item['read']]) aria-hidden="true"></span>
                                <span class="min-w-0 flex-1">
                                    <span class="flex flex-wrap items-baseline gap-x-1.5">
                                        <span class="font-mono text-xs font-bold text-brand">{{ $item['reference'] }}</span>
                                        <span @class(['font-semibold' => ! $item['read']])>{{ $item['title'] }}</span>
                                    </span>
                                    <span class="mt-0.5 line-clamp-2 block break-words text-xs text-app-text-2">{{ $item['message'] }}</span>
                                    <x-ui.date :value="$item['created_at']" class="mt-1 block font-mono text-[11px] text-app-text-2" />
                                </span>
                                @unless ($item['read'])<span class="sr-only">Unread</span>@endunless
                            </button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif

        <a href="{{ $index }}" class="flex min-h-11 items-center justify-center gap-1 border-t border-app-border px-4 text-sm font-semibold text-brand hover:bg-brand/5 focus-ring">
            See all notifications<x-ui.icon name="arrow_forward" :size="16" class="transition-transform group-hover:translate-x-1" />
        </a>
    </div>
</div>
