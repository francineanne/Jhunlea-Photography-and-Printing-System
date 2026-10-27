@props(['mark' => false, 'size' => 240, 'animate' => true])

{{-- Filled camera aperture from the loader design. Full: lens rings, six rotating blades and a hexagon opening.
     Mark: only the blades and opening, a small brand icon. Gradient ids are unique so several can sit on one page. --}}
@php
    $uid = 'ap'.Str::random(6);
    $turn = $animate ? 'animate-aperture-turn origin-[120px_120px] [transform-box:view-box]' : '';
@endphp

<svg {{ $attributes->class('shrink-0') }} width="{{ $size }}" height="{{ $size }}"
    viewBox="{{ $mark ? '36 36 168 168' : '0 0 240 240' }}" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
    <defs>
        <linearGradient id="{{ $uid }}-grad" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" stop-color="#C62828" />
            <stop offset="100%" stop-color="#8E0000" />
        </linearGradient>
        @unless ($mark)
            <filter id="{{ $uid }}-glow" x="-20%" y="-20%" width="140%" height="140%">
                <feGaussianBlur stdDeviation="3" result="blur" />
                <feComposite in="SourceGraphic" in2="blur" operator="over" />
            </filter>
        @endunless
    </defs>

    @unless ($mark)
        <circle cx="120" cy="120" r="110" fill="none" stroke="#262626" stroke-width="2" />
        <circle cx="120" cy="120" r="102" fill="none" stroke="#333333" stroke-width="1.5" stroke-dasharray="4 6" />
        <circle cx="120" cy="120" r="94" fill="#141414" stroke="#C62828" stroke-width="2"
            @class(['animate-ring-pulse origin-[120px_120px] [transform-box:view-box]' => $animate]) />
        <circle cx="120" cy="120" r="90" fill="#171717" />
    @endunless

    <g class="{{ $turn }}">
        <path d="M120 38C145 38 175 60 185 85L140 105C130 90 115 80 100 80Z" fill="url(#{{ $uid }}-grad)" opacity="0.95" />
        <path d="M185 85C198 105 198 140 185 165L145 135C150 120 145 105 138 95Z" fill="#b71c1c" opacity="0.92" />
        <path d="M185 165C165 190 135 202 110 198L125 150C135 150 148 142 152 130Z" fill="#c62828" opacity="0.95" />
        <path d="M110 198C80 198 55 178 48 152L95 132C102 145 115 152 128 150Z" fill="#991b1b" opacity="0.9" />
        <path d="M48 152C40 125 45 95 60 72L100 102C92 115 95 130 102 140Z" fill="#c62828" opacity="0.95" />
        <path d="M60 72C80 48 105 38 120 38L110 88C98 88 88 95 82 105Z" fill="#d32f2f" opacity="0.92" />
        <polygon points="120,78 154,98 154,142 120,162 86,142 86,98" fill="#0d0d0d" stroke="#c62828" stroke-width="1.5" />
    </g>

    @unless ($mark)
        <circle cx="120" cy="120" r="16" fill="#C62828" opacity="0.15" filter="url(#{{ $uid }}-glow)" />
        <circle cx="120" cy="120" r="7" fill="#C62828" />
        <circle cx="117" cy="117" r="2.5" fill="#ffffff" opacity="0.8" />
    @endunless
</svg>
