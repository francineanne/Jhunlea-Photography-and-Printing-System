@props(['variant' => 'primary', 'size' => 'md', 'href' => null, 'type' => 'button', 'loading' => false, 'disabled' => false])

@php
    $variants = [
        'primary' => 'btn-sweep text-white active:shadow-[inset_0_2px_4px_rgba(0,0,0,0.25)]',
        'secondary' => 'bg-ink text-white hover:bg-neutral-800 dark:bg-white dark:text-ink dark:hover:bg-neutral-200',
        'outline' => 'border border-neutral-200 bg-transparent text-app-text hover:bg-card dark:border-neutral-700 dark:hover:bg-white/10',
        'ghost' => 'bg-transparent text-app-text hover:bg-black/5 dark:hover:bg-white/10',
        'danger' => 'bg-red-700 text-white hover:bg-red-800',
    ];

    // The compact size is 36px on larger screens but stays 44px on phones for tap targets
    $sizes = ['md' => 'h-11 px-5', 'sm' => 'h-11 px-4 sm:h-9 sm:px-3.5'];

    $inactive = $loading || $disabled;

    $attrs = $href ? ['href' => $href] : ['type' => $type];
    if ($inactive) {
        $attrs += $href ? ['aria-disabled' => 'true', 'tabindex' => '-1'] : ['disabled' => true];
    }
    if ($loading) {
        $attrs['aria-busy'] = 'true';
    }

    $tag = $href ? 'a' : 'button';
@endphp

<{{ $tag }} {{ $attributes->class([
    'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-control text-sm font-semibold select-none focus-ring',
    'transition-[color,background-color,border-color,transform,filter] duration-[280ms] ease-brand active:scale-[0.98]',
    $variants[$variant] ?? $variants['primary'],
    $sizes[$size] ?? $sizes['md'],
    'pointer-events-none opacity-60' => $inactive,
])->merge($attrs) }}>
    @if ($loading)
        <x-ui.aperture-spinner :size="18" />
    @endif
    {{ $slot }}
</{{ $tag }}>
