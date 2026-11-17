@props(['user' => null, 'url' => null, 'initials' => null, 'size' => 'sm'])

{{--
    Phase 10C: a person's profile picture, or their initials when there is none (always for a walk-in). Square with CSS
    (object-fit: cover), round. Decorative: the name is always written next to it. Sizes: xs 24px, sm 32px, md 40px,
    xl 80px. Give either a user or a url plus initials.
--}}
@php
    $url ??= $user?->avatarUrl();
    $initials ??= $user?->initials() ?? '?';
    $box = match ($size) {
        'xs' => 'size-6 text-[10px]',
        'md' => 'size-10 text-sm',
        'xl' => 'size-20 text-3xl font-display',
        default => 'size-8 text-xs',
    };
@endphp

<span {{ $attributes->class(['inline-grid shrink-0 place-items-center overflow-hidden rounded-full bg-brand font-semibold text-white', $box]) }} aria-hidden="true" data-user-avatar>
    @if ($url)
        <img src="{{ $url }}" alt="" class="size-full object-cover" loading="lazy" decoding="async">
    @else
        {{ $initials }}
    @endif
</span>
