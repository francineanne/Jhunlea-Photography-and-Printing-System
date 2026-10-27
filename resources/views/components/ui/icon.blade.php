@props(['name', 'size' => 20, 'label' => null])

<span {{ $attributes->class('material-symbols-outlined shrink-0 select-none') }} style="font-size: {{ $size }}px"
    @if ($label) role="img" aria-label="{{ $label }}" @else aria-hidden="true" @endif>{{ $name }}</span>
