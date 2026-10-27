@props(['type' => 'aperture'])

{{-- Illustration panel of the error pages (design/login/studio_error_suite_404_403_500).
     camera: open-backed rangefinder with an empty film gate (404). lock: padlocked camera (403). aperture: jammed shutter iris (everything else). --}}
<div class="relative flex aspect-[4/3] w-full max-w-[440px] items-center justify-center overflow-hidden rounded-2xl bg-neutral-100 p-6 dark:bg-white/5">
    <div class="pointer-events-none absolute left-1/2 top-1/2 size-64 -translate-x-1/2 -translate-y-1/2 rounded-full bg-brand/10 blur-3xl"></div>

    <svg class="relative size-full max-h-[280px]" viewBox="0 0 400 300" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <g class="text-neutral-300 dark:text-neutral-700" opacity="0.6">
            <line x1="20" y1="150" x2="380" y2="150" stroke="currentColor" stroke-dasharray="3 6" />
            <line x1="200" y1="20" x2="200" y2="280" stroke="currentColor" stroke-dasharray="3 6" />
        </g>

        @if ($type === 'aperture')
            <circle cx="200" cy="150" r="92" stroke="currentColor" stroke-width="4" stroke-dasharray="2 12" class="text-neutral-300 dark:text-neutral-600" />
            <circle cx="200" cy="150" r="80" class="fill-neutral-800 dark:fill-neutral-900" />
            <g transform="translate(200 150) rotate(18)">
                <path d="M0 -66C26 -66 52 -48 62 -24L24 -4C16 -18 8 -26 -4 -26Z" fill="#C62828" opacity="0.95" />
                <path d="M62 -24C72 -2 66 26 50 46L18 18C24 8 24 0 24 -4Z" fill="#B71C1C" opacity="0.9" transform="rotate(14)" />
                <path d="M50 46C30 64 4 70 -16 62L-4 24C6 24 14 22 18 18Z" fill="#991B1B" opacity="0.9" transform="rotate(-10)" />
                <path d="M-16 62C-44 56 -62 34 -64 8L-24 6C-22 16 -12 24 -4 24Z" fill="#C62828" opacity="0.95" transform="rotate(22)" />
                <path d="M-64 8C-66 -20 -52 -48 -28 -60L-4 -26C-16 -22 -22 -10 -24 6Z" fill="#D32F2F" opacity="0.92" transform="rotate(-8)" />
                <polygon points="0,-30 26,-15 26,15 0,30 -26,15 -26,-15" class="fill-neutral-950" stroke="#C62828" stroke-width="1.5" />
            </g>
        @else
            {{-- Camera body --}}
            <rect x="76" y="96" width="248" height="136" rx="16" class="fill-neutral-300 dark:fill-neutral-800" opacity="0.5" />
            <rect x="70" y="90" width="250" height="130" rx="14" class="fill-neutral-200 dark:fill-neutral-700" />
            <path d="M70 104C70 96.3 76.3 90 84 90H306C313.7 90 320 96.3 320 104V124H70V104Z" class="fill-neutral-300 dark:fill-neutral-600" />
            <rect x="90" y="78" width="30" height="12" rx="3" class="fill-neutral-500" />
            <rect x="250" y="80" width="36" height="10" rx="2" class="fill-neutral-500" />
            <rect x="254" y="76" width="28" height="4" rx="1" fill="#C62828" />
            <circle cx="218" cy="85" r="7" class="fill-neutral-400" />
            <circle cx="218" cy="85" r="4" fill="#C62828" />
            <rect x="92" y="98" width="26" height="16" rx="4" class="fill-neutral-800" />
            <line x1="70" y1="140" x2="320" y2="140" stroke="#C62828" stroke-width="2" />

            @if ($type === 'camera')
                {{-- Open film back and an empty film gate: the frame that never developed --}}
                <g transform="rotate(-32 68 150)">
                    <rect x="24" y="104" width="50" height="112" rx="6" class="fill-neutral-300 dark:fill-neutral-600" />
                    <rect x="30" y="112" width="38" height="96" rx="4" class="fill-neutral-200 dark:fill-neutral-700" />
                </g>
                <rect x="100" y="132" width="190" height="80" rx="6" class="fill-neutral-900" />
                <rect x="110" y="140" width="170" height="64" rx="4" class="fill-neutral-800" />
                <rect x="146" y="152" width="98" height="40" rx="3" fill="#C62828" fill-opacity="0.06" stroke="#C62828" stroke-dasharray="4 4" />
                <g transform="translate(260 210) rotate(18)">
                    <ellipse cx="40" cy="30" rx="34" ry="16" class="fill-neutral-400" opacity="0.5" />
                    <ellipse cx="38" cy="26" rx="32" ry="14" class="fill-neutral-900" />
                    <ellipse cx="38" cy="26" rx="28" ry="11" class="fill-neutral-700" />
                </g>
            @else
                {{-- Padlock over the film gate --}}
                <rect x="100" y="132" width="190" height="80" rx="6" class="fill-neutral-900" />
                <path d="M178 150v-8a17 17 0 0 1 34 0v8" stroke="#C62828" stroke-width="5" stroke-linecap="round" />
                <rect x="166" y="150" width="58" height="46" rx="8" fill="#C62828" />
                <circle cx="195" cy="170" r="6" class="fill-neutral-950" />
                <rect x="192.5" y="172" width="5" height="14" rx="2" class="fill-neutral-950" />
            @endif
        @endif
    </svg>
</div>
