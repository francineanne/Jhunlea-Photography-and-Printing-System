@props(['size' => 'md'])

@php
    $sizes = ['md' => 'text-xl', 'lg' => 'text-3xl'];
@endphp

<span {{ $attributes->class(['inline-flex flex-col leading-none text-app-text', $sizes[$size] ?? $sizes['md']]) }}>
    <span class="font-display text-[1em] font-semibold tracking-[0.12em]">JHUNLEA</span>
    <span class="mt-[0.35em] whitespace-nowrap text-[0.5em] font-semibold tracking-[0.16em] text-app-text-2">PHOTOGRAPHY &amp; PRINTING</span>
</span>
