{{--
    What went wrong, shared by every chat (7F-2): an inset line above the message box with a way forward. The parent
    Alpine component provides banner ({ kind, text, retry }), runRetry(); extra actions go in the slot. kind "calm" (a
    limit, the AI unavailable) is grey; everything else red, readable in both themes.
--}}
<div x-show="banner" x-cloak role="alert" x-bind:data-kind="banner?.kind"
    x-bind:class="banner?.kind === 'calm' ? 'border-app-border bg-neutral-100 text-app-text-2 dark:bg-white/5' : 'border-status-red/30 bg-status-red-bg text-[#991B1B] dark:border-[#ffb4ac]/30 dark:bg-[#ffb4ac]/10 dark:text-[#ffb4ac]'"
    {{ $attributes->class('mx-2 mb-1 flex flex-wrap items-center gap-x-2 rounded-control border py-1 pl-3 pr-1 text-sm sm:mx-3') }}>
    <span class="material-symbols-outlined shrink-0 select-none text-[18px]" aria-hidden="true" x-text="banner?.kind === 'calm' ? 'info' : 'error'">error</span>
    <p class="min-w-0 flex-1 basis-40" x-text="banner?.text"></p>
    {{-- !!: Alpine calls an expression that evaluates to a function, which here would run the retry on every render --}}
    <button type="button" x-show="!!banner?.retry" x-on:click="runRetry()" class="min-h-11 rounded-control px-2 font-semibold underline focus-ring">Try again</button>
    {{ $slot }}
    <button type="button" x-show="banner?.kind === 'session'" x-on:click="window.location.reload()" class="min-h-11 rounded-control px-2 font-semibold underline focus-ring">Reload the page</button>
    <button type="button" x-on:click="banner = null" aria-label="Dismiss this message" class="grid size-11 shrink-0 place-items-center rounded-control focus-ring"><x-ui.icon name="close" :size="18" /></button>
</div>
