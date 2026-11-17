@props(['items', 'label' => 'Main', 'variant' => 'stack', 'icons' => true])

{{--
    items: [['label' => ..., 'icon' => ..., 'route' => route name, 'match' => optional route pattern such as
    'owner.services.*', or a list of them, 'badge' => optional count, 'short' => optional shorter label], ...]; the
    active route gets aria-current="page".
    Phase 10D: a top item never wraps (whitespace-nowrap) and the active item differs by background and colour only: the
    same padding, weight and border as the others, so nothing moves when the page changes. `short` is shown below 1280px
    in the top bar; `icons` false leaves the icons out of the top bar (the six customer items need the room).
    In the collapsed owner sidebar (.sidebar-collapsed) only the icons show and each item names itself in a tooltip.
--}}
<nav aria-label="{{ $label }}" {{ $attributes }}>
    <ul @class(['flex items-center gap-1' => $variant === 'top', 'space-y-1' => $variant !== 'top'])>
        @foreach ($items as $item)
            @php $active = request()->routeIs(...(array) ($item['match'] ?? $item['route'])); @endphp
            <li>
                <a href="{{ route($item['route']) }}" @if ($active) aria-current="page" @endif
                    @if ($variant !== 'top') title="{{ $item['label'] }}" @endif
                    @class([
                        'nav-item flex min-h-11 items-center text-sm font-semibold transition-colors duration-150 focus-ring',
                        'gap-2 whitespace-nowrap rounded-xl px-3' => $variant === 'top',
                        'gap-3 rounded-lg px-3.5' => $variant !== 'top',
                        'bg-brand text-white' => $active,
                        'text-app-text-2 hover:bg-black/5 hover:text-app-text dark:hover:bg-white/10' => ! $active,
                    ])>
                    @if ($icons || $variant !== 'top')
                        <x-ui.icon :name="$item['icon']" :size="20" class="shrink-0" />
                    @endif
                    @if ($variant === 'top' && filled($item['short'] ?? null))
                        <span class="xl:hidden" aria-hidden="true">{{ $item['short'] }}</span><span class="max-xl:sr-only">{{ $item['label'] }}</span>
                    @else
                        {{ $item['label'] }}
                    @endif
                    @if (($item['badge'] ?? 0) > 0)
                        <span @class(['nav-badge ml-auto rounded-full px-2 py-0.5 text-[11px] font-bold tabular-nums', 'bg-white text-brand' => $active, 'bg-brand text-white' => ! $active])><span class="sr-only">, </span>{{ $item['badge'] > \App\Services\NotificationFeed::BADGE_MAX ? \App\Services\NotificationFeed::BADGE_MAX.'+' : $item['badge'] }}<span class="sr-only"> unread</span></span>
                    @endif
                </a>
            </li>
        @endforeach
    </ul>
</nav>
