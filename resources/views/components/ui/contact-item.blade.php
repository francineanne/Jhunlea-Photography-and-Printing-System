@props(['icon', 'label', 'href' => null, 'external' => false, 'note' => null])

{{--
    Phase 10G: ONE contact line (landing page "Visit us"): an icon box centred against a small uppercase label with its
    value directly under it (a 2 px gap, fixed line heights, no paragraph margins). When there is a link (tel:, mailto:,
    the Facebook page) the WHOLE line is the link, so the tap target stays at least 44 px tall without pushing the value
    away from its label.
--}}
@php
    $tag = $href ? 'a' : 'div';
    $row = 'flex min-h-11 items-center gap-3 rounded-control';
@endphp

<{{ $tag }} @if ($href) href="{{ $href }}" @if ($external) target="_blank" rel="noopener noreferrer external" @endif @endif
    {{ $attributes->class([$row, 'group -mx-1.5 px-1.5 py-1 transition-colors duration-150 hover:bg-black/[0.03] focus-ring dark:hover:bg-white/5' => $href]) }} data-contact-item>
    <span class="grid size-10 shrink-0 place-items-center rounded-control bg-brand/10 text-brand dark:text-[#ffb4ac]" aria-hidden="true"><x-ui.icon :name="$icon" :size="22" /></span>
    <span class="min-w-0 flex-1">
        <span class="block text-[11px] font-bold uppercase leading-4 tracking-wider text-app-text-2" data-contact-label>{{ $label }}</span>
        <span @class(['mt-0.5 block break-words font-semibold leading-snug text-app-text', 'group-hover:text-brand dark:group-hover:text-[#ffb4ac]' => $href]) data-contact-value>{{ $slot }}@if ($href && $external)<x-ui.icon name="open_in_new" :size="16" class="ml-1 inline-block align-[-3px]" /><span class="sr-only"> (opens in a new tab)</span>@endif</span>
        @if ($note)
            <span class="mt-0.5 block text-sm leading-snug text-app-text-2">{{ $note }}</span>
        @endif
    </span>
</{{ $tag }}>
