@props(['items', 'label' => 'Main', 'variant' => 'stack'])

{{-- items: [['label' => ..., 'icon' => ..., 'route' => route name, 'match' => optional route pattern such as 'owner.services.*'], ...]; the active route gets aria-current="page" --}}
<nav aria-label="{{ $label }}" {{ $attributes }}>
    <ul @class(['flex items-center gap-1' => $variant === 'top', 'space-y-1' => $variant !== 'top'])>
        @foreach ($items as $item)
            @php $active = request()->routeIs($item['match'] ?? $item['route']); @endphp
            <li>
                <a href="{{ route($item['route']) }}" @if ($active) aria-current="page" @endif
                    @class([
                        'flex min-h-11 items-center gap-3 px-3.5 text-sm font-semibold transition-colors duration-150 focus-ring',
                        'rounded-xl' => $variant === 'top',
                        'rounded-lg' => $variant !== 'top',
                        'bg-brand text-white shadow-sm' => $active,
                        'text-app-text-2 hover:bg-black/5 hover:text-app-text dark:hover:bg-white/10' => ! $active,
                    ])>
                    <x-ui.icon :name="$item['icon']" :size="20" />
                    {{ $item['label'] }}
                </a>
            </li>
        @endforeach
    </ul>
</nav>
