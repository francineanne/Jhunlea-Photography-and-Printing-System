@props(['size' => 24, 'label' => null])

{{-- Six chords of equal length rotated 60 degrees apart leave a hexagonal opening, like camera blades --}}
<svg {{ $attributes->class('animate-aperture shrink-0') }} width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24"
    fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
    @if ($label) role="img" aria-label="{{ $label }}" @else aria-hidden="true" @endif>
    <circle cx="12" cy="12" r="10" />
    @foreach ([0, 60, 120, 180, 240, 300] as $angle)
        <path d="M22 12L4.34 18.43" transform="rotate({{ $angle }} 12 12)" />
    @endforeach
</svg>
