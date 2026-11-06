@props(['size' => 'md'])

{{-- The brand mark: the red rounded square with the aperture (camera) icon, as in the site header. Used by <x-ui.logo mark>
     and, as the avatar of Lumi and the studio, by <x-chat.avatar>. Decorative: the parent names it. --}}
@php
    $sizes = [
        'sm' => ['box' => 'size-8 rounded-[10px]', 'icon' => 18],
        'md' => ['box' => 'size-9 rounded-xl', 'icon' => 20],
        'lg' => ['box' => 'size-10 rounded-xl', 'icon' => 22],
    ];
    $s = $sizes[$size] ?? $sizes['md'];
@endphp

<span {{ $attributes->class(['grid shrink-0 place-items-center bg-brand text-white', $s['box']]) }} aria-hidden="true">
    <x-ui.icon name="camera" :size="$s['icon']" />
</span>
