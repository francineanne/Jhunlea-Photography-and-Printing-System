@props(['text', 'size' => 'md', 'label' => null])

{{--
    Phase 10D: the ONE way a catalog price is shown (package and service cards, wizard, detail pages): one font, size and
    weight for the amount, and the note ("final price confirmed after review") as small muted text on its own line.
    text: the model's own priceText(), which stays the one place that words a price.
--}}
@php
    [$amount, $note] = array_pad(explode(' — ', (string) $text, 2), 2, null);
    $scale = ['sm' => 'text-sm', 'md' => 'text-lg', 'lg' => 'text-2xl'][$size] ?? 'text-lg';
@endphp
<div {{ $attributes->class('min-w-0') }} data-price>
    @if ($label)
        <p class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">{{ $label }}</p>
    @endif
    <p class="{{ $scale }} font-bold leading-tight tabular-nums text-app-text">{{ $amount }}</p>
    @if (filled($note))
        <p class="mt-0.5 text-xs leading-snug text-app-text-2">{{ ucfirst($note) }}</p>
    @endif
</div>
