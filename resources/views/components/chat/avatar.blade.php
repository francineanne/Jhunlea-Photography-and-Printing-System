@props(['who' => 'lumi', 'size' => 'sm', 'decorative' => false])

{{--
    Phase 10F: Lumi and the studio never share a picture.
    - Lumi (the AI): its own mark, an assistant sparkle on the violet tint of the design tokens, with the small "AI"
      badge. Never the shop logo.
    - The studio (a person): the shop's camera mark in a NEUTRAL round frame. In a conversation the owner's own profile
      picture is shown instead when one is uploaded (<x-chat.rows>).
    The accessible name says who it is, so the two are never told apart by the picture or a colour alone. `decorative`
    hides it when the text beside it already names who it is. Extra attributes (x-show and the like) go on the outer element.
--}}
@php
    $lumi = $who === 'lumi';
    $label = $lumi ? config('assistant.name').', AI assistant' : 'Studio';
    $box = ['sm' => 'size-8', 'md' => 'size-9', 'lg' => 'size-10'][$size] ?? 'size-8';
    $icon = ['sm' => 18, 'md' => 20, 'lg' => 22][$size] ?? 18;
@endphp

<span {{ $attributes->class('relative inline-grid shrink-0') }}
    @if ($decorative) aria-hidden="true" @else role="img" aria-label="{{ $label }}" title="{{ $label }}" @endif data-chat-avatar="{{ $lumi ? 'lumi' : 'studio' }}">
    @if ($lumi)
        <span class="grid {{ $box }} place-items-center rounded-full bg-status-violet-bg text-status-violet shadow-sm ring-1 ring-status-violet/25" aria-hidden="true">
            {{-- A four-point sparkle: the assistant's own mark --}}
            <svg viewBox="0 0 24 24" width="{{ $icon }}" height="{{ $icon }}" fill="currentColor" aria-hidden="true"><path d="M12 2.5c.5 4.6 2.4 7.1 7.5 9.5-5.1 2.4-7 4.9-7.5 9.5-.5-4.6-2.4-7.1-7.5-9.5 5.1-2.4 7-4.9 7.5-9.5z"/><path d="M19 2.5c.2 1.5.8 2.3 2.5 3-1.7.7-2.3 1.5-2.5 3-.2-1.5-.8-2.3-2.5-3 1.7-.7 2.3-1.5 2.5-3z" opacity=".7"/></svg>
        </span>
        <span class="absolute -bottom-1 -right-1 rounded-full bg-ink px-1 py-px text-[8px] font-bold leading-none tracking-wide text-white ring-2 ring-app-card dark:bg-white dark:text-ink" aria-hidden="true">AI</span>
    @else
        <span class="grid {{ $box }} place-items-center rounded-full border border-app-border bg-app-card text-brand shadow-sm dark:bg-white/10 dark:text-[#ffb4ac]" aria-hidden="true">
            <x-ui.icon name="camera" :size="$icon" />
        </span>
    @endif
</span>
