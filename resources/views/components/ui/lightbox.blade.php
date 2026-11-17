{{--
    Phase 10D: the ONE photo viewer (package and backdrop photos on the public pages and in the booking wizard). Place
    once per layout. Any link written as
        <a href="/storage/photo.jpg" data-lightbox="group-name" data-alt="What the photo shows">
    opens here at full size instead of leaving the page; links of the same group can be stepped through.
    Previous / next, "2 of 5", Esc and the close button, arrow keys, swipe, focus trapped and returned, page scroll
    locked, no motion with reduced motion, and the photo keeps its alt text. Without JavaScript the link simply opens
    the photo. No library.
--}}
<div x-data="uiLightbox" x-on:lightbox-open.window="openAt($event.detail)" x-on:keydown.escape.window="close()"
    x-on:keydown.arrow-left.window="step(-1)" x-on:keydown.arrow-right.window="step(1)" x-on:keydown.tab="trap($event)"
    x-show="open" x-cloak x-transition.opacity.duration.200ms role="dialog" aria-modal="true" aria-label="Photo viewer"
    class="fixed inset-0 z-[80] flex flex-col bg-black/90 text-white" data-lightbox-viewer>
    <div class="flex items-center justify-between gap-3 px-3 py-2 sm:px-5">
        <p class="text-sm font-semibold tabular-nums" aria-live="polite"><span x-text="index + 1"></span> of <span x-text="items.length"></span></p>
        <button type="button" x-ref="close" x-on:click="close()" aria-label="Close the photo viewer"
            class="grid size-11 place-items-center rounded-full bg-white/10 transition-colors hover:bg-white/20 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">
            <span class="material-symbols-outlined text-[22px]" aria-hidden="true">close</span>
        </button>
    </div>

    <div class="relative flex min-h-0 flex-1 items-center justify-center px-2 pb-2 sm:px-16" x-on:click.self="close()"
        x-on:touchstart.passive="touchStart($event)" x-on:touchend.passive="touchEnd($event)">
        <img x-bind:src="current.src" x-bind:alt="current.alt" class="max-h-full max-w-full select-none rounded object-contain" draggable="false">

        <button type="button" x-show="items.length > 1" x-on:click="step(-1)" aria-label="Go back one photo"
            class="absolute left-2 top-1/2 grid size-11 -translate-y-1/2 place-items-center rounded-full bg-white/15 transition-colors hover:bg-white/30 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white sm:left-4">
            <span class="material-symbols-outlined text-[24px]" aria-hidden="true">chevron_left</span>
        </button>
        <button type="button" x-show="items.length > 1" x-on:click="step(1)" aria-label="Go forward one photo"
            class="absolute right-2 top-1/2 grid size-11 -translate-y-1/2 place-items-center rounded-full bg-white/15 transition-colors hover:bg-white/30 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white sm:right-4">
            <span class="material-symbols-outlined text-[24px]" aria-hidden="true">chevron_right</span>
        </button>
    </div>

    <p class="min-h-6 px-4 pb-[max(0.75rem,env(safe-area-inset-bottom))] text-center text-sm text-white/80" x-text="current.alt"></p>
</div>
