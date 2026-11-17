@props(['package', 'eager' => false, 'zoom' => true])

{{--
    Phase 10D: the photo area of every package card (landing, list, finder, wizard): always 4:3, object-fit cover, with
    the width and height set so nothing shifts while it loads, and the designed placeholder when there is no photo.
--}}
@php($cover = $package->coverImage)
<div {{ $attributes->class('relative aspect-[4/3] overflow-hidden bg-card') }}>
    @if ($cover)
        <img src="{{ $cover->url() }}" alt="" width="800" height="600" loading="{{ $eager ? 'eager' : 'lazy' }}" decoding="async"
            @class(['size-full object-cover', 'transition-transform duration-500 ease-out group-hover:scale-105' => $zoom])>
    @else
        <x-ui.image-placeholder class="size-full" :label="$package->name" :category="$package->category" />
    @endif
    {{ $slot }}
</div>
