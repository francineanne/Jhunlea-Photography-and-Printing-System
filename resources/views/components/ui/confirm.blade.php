{{--
    Phase 10D: the ONE confirmation dialog. Place once per layout. It is never the browser's own confirm().

    Any form (or one of its submit buttons) asks first when it carries:
        data-confirm="Cancel this booking?"                 the title (required)
        data-confirm-text="One or two plain sentences."     what will happen
        data-confirm-detail="PHP 1,000.00, due Nov 20"      optional: the amount or date, shown strong
        data-confirm-ok="Cancel booking"                    the action button, named by the action
        data-confirm-cancel="Go back"                       optional, "Go back" when left out
        data-confirm-danger                                 red action button for a destructive action
    A button's own attributes win over the form's. Without JavaScript the form simply posts; the server still decides.
    Esc, the backdrop and the browser's back button close it; focus is trapped and returned. On phones it is a bottom sheet.
--}}
<div x-data="uiConfirm" x-on:confirm-ask.window="ask($event.detail)" x-on:keydown.escape.window="cancel()" x-on:keydown.tab="trap($event)"
    x-show="open" x-cloak class="fixed inset-0 z-[70] flex items-end justify-center sm:items-center sm:p-4" data-confirm-dialog>
    <div x-show="open" x-transition.opacity.duration.200ms x-on:click="cancel()" class="absolute inset-0 bg-ink/50 backdrop-blur-[12px]" aria-hidden="true"></div>

    <div x-ref="panel" x-show="open" tabindex="-1" role="alertdialog" aria-modal="true" aria-labelledby="confirm-title" aria-describedby="confirm-text"
        x-transition:enter="transition duration-[220ms] ease-brand" x-transition:enter-start="translate-y-4 opacity-0" x-transition:enter-end="translate-y-0 opacity-100"
        class="relative w-full max-w-md rounded-t-2xl border border-app-border bg-app-card p-5 pb-[max(1.25rem,env(safe-area-inset-bottom))] shadow-[0_24px_48px_-12px_rgba(23,23,23,0.25)] focus:outline-none sm:rounded-card sm:p-6 dark:shadow-2xl">
        <div class="flex items-start gap-3">
            <span class="grid size-10 shrink-0 place-items-center rounded-full" x-bind:class="danger ? 'bg-status-red-bg text-status-red' : 'bg-brand/10 text-brand'" aria-hidden="true">
                <span class="material-symbols-outlined text-[22px]" x-text="danger ? 'warning' : 'help'"></span>
            </span>
            <div class="min-w-0">
                <h2 id="confirm-title" class="font-display text-xl font-semibold leading-snug" x-text="title"></h2>
                <p id="confirm-text" class="mt-1.5 break-words text-sm leading-relaxed text-app-text-2" x-show="text" x-text="text"></p>
                <p class="mt-2 break-words text-sm font-bold tabular-nums" x-show="detail" x-text="detail"></p>
            </div>
        </div>
        <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <button type="button" x-on:click="cancel()" class="inline-flex h-11 items-center justify-center rounded-control border border-neutral-200 px-5 text-sm font-semibold transition-colors hover:bg-card focus-ring dark:border-neutral-700 dark:hover:bg-white/10" x-text="cancelLabel"></button>
            <button type="button" x-ref="ok" x-on:click="accept()" class="inline-flex h-11 items-center justify-center gap-2 rounded-control px-5 text-sm font-semibold text-white transition-colors focus-ring active:scale-[0.98]"
                x-bind:class="danger ? 'bg-red-700 hover:bg-red-800' : 'bg-brand hover:bg-brand-hover'" x-text="okLabel"></button>
        </div>
    </div>
</div>
