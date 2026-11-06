@props(['text'])

{{-- Lumi is working (7F-2), shared by every chat: Lumi's avatar and a calm bubble with three dots (still with reduced motion) --}}
<div {{ $attributes->class('mt-4 flex items-center gap-2') }}>
    <x-chat.avatar who="lumi" decorative />
    <p class="flex items-center gap-2 rounded-card rounded-tl-md bg-neutral-100 px-3.5 py-2.5 text-xs text-app-text-2 dark:bg-white/10">
        <span class="flex items-center gap-1" aria-hidden="true">
            <span class="size-1.5 animate-bounce rounded-full bg-brand motion-reduce:animate-none"></span>
            <span class="size-1.5 animate-bounce rounded-full bg-brand [animation-delay:0.2s] motion-reduce:animate-none"></span>
            <span class="size-1.5 animate-bounce rounded-full bg-brand [animation-delay:0.4s] motion-reduce:animate-none"></span>
        </span>
        {{ $text }}
    </p>
</div>
