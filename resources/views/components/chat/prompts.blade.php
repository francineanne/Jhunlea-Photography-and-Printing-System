@props(['prompts' => [], 'click', 'marker', 'max' => 4])

{{--
    "Try asking" (7H.1), shared by every chat with an empty state (customer and guest panel, /my/assistant, the owner's
    Ask Lumi panel and page). It sits at the end of the message list, so in a bottom-anchored list it rests directly above
    the message box and scrolls with the list; it never pushes the box. `click` is the Alpine call with %s for the prompt
    (as a JS string), `marker` the data attribute each chip carries, `max` how many chips show (4 in a panel, 5 on a page).
--}}
@php
    $shown = array_slice(array_values((array) $prompts), 0, (int) $max);
@endphp

<div {{ $attributes->class('mt-3 flex flex-col gap-1.5') }}>
    <p class="text-[11px] font-semibold text-app-text-2">Try asking</p>
    <div class="flex flex-wrap gap-1.5">
        @foreach ($shown as $prompt)
            {{-- Raw like @js: Js::from() hex-escapes quotes, < > and &, so the configured prompt is safe inside the attribute; $click is a fixed expression from the view --}}
            <button type="button" {{ $marker }} x-on:click="{!! sprintf($click, \Illuminate\Support\Js::from($prompt)) !!}"
                class="min-h-11 rounded-full border border-app-border bg-app-card px-3 py-1 text-left text-xs font-medium text-app-text transition-colors duration-150 hover:border-brand hover:text-brand focus-ring sm:min-h-8 dark:bg-white/5 dark:hover:text-[#ffb4ac]">{{ $prompt }}</button>
        @endforeach
    </div>
</div>
