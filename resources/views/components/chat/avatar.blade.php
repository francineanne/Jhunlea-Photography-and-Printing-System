@props(['who' => 'lumi', 'size' => 'sm', 'decorative' => false])

{{--
    The avatar of Lumi (the AI) and of the studio (a person): both are the brand mark of the header (<x-ui.logo-mark>).
    Lumi's carries a small "AI" badge on its corner; the studio's has none. The accessible name says who it is, so the two
    are never told apart by the picture or a colour alone. `decorative` hides it when the text beside it already names
    who it is. Extra attributes (x-show and the like) go on the outer element.
--}}
@php
    $lumi = $who === 'lumi';
    $label = $lumi ? config('assistant.name').', AI assistant' : 'Studio';
@endphp

<span {{ $attributes->class('relative inline-grid shrink-0') }}
    @if ($decorative) aria-hidden="true" @else role="img" aria-label="{{ $label }}" @endif data-chat-avatar="{{ $lumi ? 'lumi' : 'studio' }}">
    <x-ui.logo-mark :size="$size" class="shadow-sm" />
    @if ($lumi)
        <span class="absolute -bottom-1 -right-1 rounded-full bg-ink px-1 py-px text-[8px] font-bold leading-none tracking-wide text-white ring-2 ring-app-card dark:bg-white dark:text-ink" aria-hidden="true">AI</span>
    @endif
</span>
