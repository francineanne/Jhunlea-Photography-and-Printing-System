@props(['size' => 'md', 'mark' => false, 'compact' => false])

@php
    $sizes = ['md' => 'text-xl', 'lg' => 'text-3xl'];
@endphp

{{-- compact: drops the tagline on phones so a header with several buttons still fits. mark: red rounded square with the camera icon, shown in the public and customer headers (the parent link carries `group` for the hover) --}}
<span {{ $attributes->class(['inline-flex items-center gap-2.5 text-app-text', $sizes[$size] ?? $sizes['md']]) }}>
    @if ($mark)
        <span class="grid size-9 shrink-0 place-items-center rounded-xl bg-brand text-white transition-transform duration-200 group-hover:scale-95" aria-hidden="true">
            <x-ui.icon name="camera" :size="20" />
        </span>
    @endif
    <span class="inline-flex flex-col leading-none">
        <span class="font-display text-[1em] font-bold tracking-[0.18em]">JHUNLEA</span>
        <span @class(['mt-[0.35em] whitespace-nowrap text-[0.5em] font-bold tracking-[0.14em] text-app-text-2', 'hidden sm:block' => $compact])>PHOTOGRAPHY &amp; PRINTING</span>
    </span>
</span>
