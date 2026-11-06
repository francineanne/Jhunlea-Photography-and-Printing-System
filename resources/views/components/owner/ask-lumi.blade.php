@props(['mode' => 'compact', 'history' => null])

{{--
    The owner's Ask Lumi (Phase 7E-3b): the floating launcher and panel (compact) and the full page (full) are this one
    component and the Alpine component `ownerAsk` (resources/js/owner-ask.js). It only reads: every answer comes from
    the read-only tools. Everything from an answer, a name or a link is drawn with x-text (or {{ }} without JavaScript),
    never as HTML, and links are relative owner paths the server built.
--}}
@php
    $full = $mode === 'full';
    $name = config('assistant.name');
    $liteName = $name.' Lite';
    $liteText = 'Our backup assistant answered this because '.$name.' is busy. Answers may be shorter.';
    $config = [
        'mode' => $mode,
        'urls' => ['ask' => route('owner.assistant.ask'), 'history' => route('owner.assistant.history')],
        'texts' => [
            'maxChars' => (int) config('chat.message_max_chars'),
            'generic' => 'Something went wrong. Please try again.',
            'offline' => 'You seem to be offline. Check your connection and try again.',
            'expired' => config('chat.messages.session_expired'),
            'signedOut' => 'You are no longer signed in as the owner. Please reload the page and sign in again.',
            'minute' => config('assistant.messages.owner_ask_limit_minute'),
        ],
    ];
    $action = 'inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center gap-1.5 rounded-control px-2.5 text-sm font-semibold transition-colors duration-150 focus-ring';
@endphp

<div x-data="ownerAsk(@js($config))" @unless ($full) x-on:keydown.escape.window="close()" @endunless {{ $attributes->class($full ? 'h-full' : '') }} data-owner-ask="{{ $mode }}">
    @unless ($full)
        <button type="button" x-ref="launcher" x-show="!open" x-on:click="show()" data-owner-ask-launcher x-cloak
            class="fixed bottom-[max(1.5rem,env(safe-area-inset-bottom))] right-4 z-30 flex min-h-11 items-center gap-2.5 rounded-full border border-app-border bg-app-card py-1.5 pl-1.5 pr-4 shadow-[0_12px_24px_-6px_rgba(23,23,23,0.18),0_4px_8px_-2px_rgba(23,23,23,0.08)] transition-transform duration-[280ms] ease-brand hover:scale-[1.02] focus-ring motion-reduce:transition-none motion-reduce:hover:scale-100 sm:right-6"
            aria-label="Open Ask {{ $name }}, the AI assistant for your shop's records">
            <x-chat.avatar who="lumi" size="md" decorative />
            <span class="text-left leading-tight" aria-hidden="true">
                <span class="block text-sm font-semibold">Ask {{ $name }}</span>
                <span class="block text-[10px] font-bold uppercase tracking-wider text-brand dark:text-[#ffb4ac]">Your shop's records</span>
            </span>
        </button>
    @endunless

    <section x-ref="panel" role="{{ $full ? 'region' : 'dialog' }}" aria-label="Ask {{ $name }} about your shop"
        @unless ($full) x-show="open" x-cloak x-on:keydown.tab="trap($event)" x-transition:enter="transition duration-[280ms] ease-brand motion-reduce:transition-none" x-transition:enter-start="translate-y-4 opacity-0" x-transition:enter-end="translate-y-0 opacity-100" @endunless
        @class([
            'flex flex-col overflow-hidden',
            'h-full rounded-card border border-app-border bg-app-card shadow-sm dark:shadow-none' => $full,
            'chat-glass chat-sheet fixed inset-x-0 z-[45] sm:inset-x-auto sm:bottom-6 sm:right-6 sm:h-[min(42rem,calc(100dvh-3rem))] sm:w-[26.5rem] sm:rounded-card sm:border sm:border-app-border sm:shadow-[0_24px_48px_-12px_rgba(23,23,23,0.25)]' => ! $full,
        ])>

        <header class="flex items-center gap-3 bg-neutral-100/80 p-3 dark:bg-white/5 sm:px-4">
            <x-chat.avatar who="lumi" size="lg" />
            <div class="min-w-0 flex-1">
                <p class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                    <span class="font-semibold">Ask {{ $name }}</span>
                    <span class="rounded bg-brand/10 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-brand dark:bg-[#ffb4ac]/15 dark:text-[#ffb4ac]">AI assistant</span>
                </p>
                <p class="text-xs text-app-text-2">Reads your records; it never changes anything</p>
            </div>
            <div class="flex shrink-0 items-center gap-1">
                <button type="button" x-on:click="newQuestion()" x-bind:disabled="waiting" title="New question" class="{{ $action }} text-app-text hover:bg-black/5 disabled:opacity-50 dark:hover:bg-white/10" data-owner-ask-new>
                    <x-ui.icon name="restart_alt" /><span class="{{ $full ? 'hidden md:inline' : 'sr-only' }}">New question</span>
                </button>
                @unless ($full)
                    <a href="{{ route('owner.assistant.index') }}" title="Open the full page" class="{{ $action }} text-app-text-2 hover:bg-black/5 hover:text-app-text dark:hover:bg-white/10" data-owner-ask-full>
                        <x-ui.icon name="open_in_full" :size="18" /><span class="sr-only">Open the full page</span>
                    </a>
                    <button type="button" x-on:click="close()" aria-label="Close Ask {{ $name }}" title="Close" class="{{ $action }} text-app-text-2 hover:bg-black/5 hover:text-app-text dark:hover:bg-white/10">
                        <x-ui.icon name="close" />
                    </button>
                @endunless
            </div>
        </header>

        @if ($full)
            {{-- Without JavaScript: the conversation so far, and a clear note --}}
            <noscript>
                <p class="border-b border-app-border bg-status-amber-bg px-4 py-2 text-sm text-[#92400E]">Turn on JavaScript to ask {{ $name }}. Your earlier questions and answers are below.</p>
            </noscript>
        @endif

        <div x-ref="log" role="log" aria-live="polite" aria-label="Questions and answers" tabindex="0" class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-3 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand/40 sm:p-4">
            @if ($full && $history !== null)
                {{-- Server-drawn copy for no JavaScript; Alpine hides it and draws its own list --}}
                <div x-show="false" class="flex flex-col gap-3" data-owner-ask-static>
                    @forelse ($history as $turn)
                        <div class="{{ $turn->role === 'owner' ? 'self-end bg-brand-deep text-white' : 'self-start bg-neutral-100 dark:bg-white/10' }} max-w-[92%] whitespace-pre-wrap rounded-card px-3 py-2 text-sm [overflow-wrap:anywhere]">{{ $turn->text }}</div>
                    @empty
                        <p class="text-sm text-app-text-2">No questions yet.</p>
                    @endforelse
                </div>
            @endif

            <button type="button" x-show="older" x-cloak x-on:click="loadOlder()" x-bind:disabled="loadingOlder" data-owner-ask-older
                class="mx-auto inline-flex min-h-11 items-center gap-1.5 rounded-full border border-app-border px-4 text-sm font-semibold text-app-text-2 hover:bg-black/5 disabled:opacity-60 focus-ring dark:hover:bg-white/5">
                <x-ui.icon name="expand_less" :size="18" />Load older
            </button>

            <p x-show="loading" x-cloak class="m-auto flex items-center gap-2 text-sm text-app-text-2"><x-ui.aperture-spinner :size="20" />Loading your questions</p>

            {{-- The greeting, for a conversation with no question yet --}}
            <div x-show="showChips" x-cloak class="flex flex-col gap-3" data-owner-ask-chips>
                <div class="flex max-w-[92%] items-start gap-2 self-start">
                    <x-chat.avatar who="lumi" />
                    <p class="rounded-card rounded-tl-none bg-neutral-100 px-3 py-2 text-sm dark:bg-white/10">Hi! I'm {{ $name }}, an AI assistant. Ask me about payments, unpaid balances, orders, bookings, the schedule, customers or messages. I only read your records; I never change anything.</p>
                </div>
                <p class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Try asking</p>
                <div class="flex flex-wrap gap-1.5">
                    @foreach (config('assistant.owner.suggested_prompts') as $prompt)
                        <button type="button" x-on:click="ask(@js($prompt))" data-owner-ask-chip class="min-h-11 rounded-full bg-neutral-100 px-3 py-1.5 text-left text-sm transition-colors duration-150 hover:bg-neutral-200 focus-ring dark:bg-white/10 dark:hover:bg-white/15">{{ $prompt }}</button>
                    @endforeach
                </div>
            </div>

            <template x-for="row in rows" x-bind:key="row.key">
                <div class="flex flex-col">
                    {{-- A limit or the AI being unavailable: a calm notice --}}
                    <template x-if="row.role === 'notice'">
                        <p class="mx-auto flex max-w-[90%] items-start gap-1.5 rounded-control bg-neutral-100 px-3 py-2 text-center text-xs text-app-text-2 [overflow-wrap:anywhere] dark:bg-white/5" data-owner-ask-notice x-bind:data-notice="row.notice">
                            <x-ui.icon name="schedule" :size="16" class="mt-px shrink-0 text-brand dark:text-[#ffb4ac]" /><span x-text="row.text"></span>
                        </p>
                    </template>

                    <template x-if="row.role !== 'notice'">
                        <div class="flex max-w-[92%] items-start gap-2" x-bind:class="row.role === 'owner' ? 'flex-row-reverse self-end' : 'self-start'" x-bind:data-owner-ask-row="row.role">
                            <x-chat.avatar who="lumi" x-show="row.role === 'lumi'" />
                            <span x-show="row.role !== 'lumi'" class="material-symbols-outlined grid size-8 shrink-0 select-none place-items-center rounded-full bg-ink text-[16px] text-white shadow-sm dark:bg-neutral-600" aria-hidden="true">person</span>

                            <div class="flex min-w-0 flex-col gap-1" x-bind:class="row.role === 'owner' ? 'items-end' : 'items-start'">
                                <p class="flex flex-wrap items-center gap-x-1.5 text-xs" x-bind:class="row.role === 'owner' ? 'justify-end' : ''">
                                    <span class="font-semibold" x-text="row.role === 'owner' ? 'You' : @js($name)"></span>
                                    <span x-show="row.role === 'lumi'" class="rounded bg-brand/10 px-1 py-px text-[9px] font-bold uppercase tracking-wider text-brand dark:bg-[#ffb4ac]/15 dark:text-[#ffb4ac]">AI</span>
                                    {{-- The backup provider wrote this answer; which provider is never shown --}}
                                    <span x-show="row.role === 'lumi' && row.via_backup" x-cloak title="{{ $liteText }}" tabindex="0" data-owner-ask-lite
                                        class="inline-flex items-center gap-0.5 whitespace-nowrap rounded-full bg-neutral-100 px-1.5 py-px text-[10px] font-semibold text-app-text-2 focus-ring dark:bg-white/10"><span class="material-symbols-outlined select-none text-[12px] leading-none" aria-hidden="true">bolt</span>{{ $liteName }}<span class="sr-only">: {{ $liteText }}</span></span>
                                    <span class="font-mono text-[11px] text-app-text-2" x-show="row.time" x-text="row.time"></span>
                                </p>

                                <div class="whitespace-pre-wrap rounded-card px-3 py-2 text-sm [overflow-wrap:anywhere]"
                                    x-bind:class="row.role === 'owner' ? 'rounded-tr-none bg-brand-deep text-white' : 'rounded-tl-none bg-neutral-100 text-app-text dark:bg-white/10'"
                                    x-text="row.text"></div>

                                <p x-show="row.status === 'sending'" class="text-[11px] text-app-text-2">Sending</p>
                                <p x-show="row.status === 'failed'" class="flex flex-wrap items-center justify-end gap-x-1 text-xs" data-owner-ask-failed>
                                    <span class="inline-flex items-center gap-1 font-semibold text-status-red dark:text-[#ffb4ac]"><x-ui.icon name="error" :size="16" />Not sent.</span>
                                    <button type="button" x-on:click="retryMessage(row)" x-bind:disabled="waiting" class="min-h-11 rounded px-2 font-semibold text-status-red underline focus-ring dark:text-[#ffb4ac]">Retry</button>
                                    <button type="button" x-on:click="discardMessage(row)" class="min-h-11 rounded px-2 font-semibold text-app-text-2 underline focus-ring">Discard</button>
                                </p>

                                {{-- Records and pages the answer is about: only relative owner links the server built --}}
                                <div x-show="row.cards.length || row.links.length" class="flex flex-wrap gap-1.5" data-owner-ask-links>
                                    <template x-for="card in row.cards" x-bind:key="'c' + card.url + card.label">
                                        <a x-bind:href="card.url" class="inline-flex min-h-11 items-center gap-1 rounded-full border border-app-border bg-app-card px-3 font-mono text-xs hover:border-brand hover:text-brand focus-ring sm:min-h-9" data-owner-ask-card>
                                            <x-ui.icon name="receipt_long" :size="14" /><span class="[overflow-wrap:anywhere]" x-text="card.label"></span>
                                        </a>
                                    </template>
                                    <template x-for="link in row.links" x-bind:key="'l' + link.url + link.label">
                                        <a x-bind:href="link.url" class="inline-flex min-h-11 items-center gap-1 rounded-control px-2 text-sm font-semibold text-brand hover:underline focus-ring dark:text-[#ffb4ac] sm:min-h-9">
                                            <span x-text="link.label"></span><x-ui.icon name="arrow_forward" :size="16" />
                                        </a>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </template>

            <div x-show="waiting" x-cloak class="flex items-center gap-2" data-owner-ask-typing>
                <x-chat.avatar who="lumi" decorative />
                <p class="flex items-center gap-2 rounded-full bg-neutral-100 px-3 py-2 text-xs text-app-text-2 dark:bg-white/10">
                    <span class="flex items-center gap-1" aria-hidden="true">
                        <span class="size-1.5 animate-bounce rounded-full bg-brand motion-reduce:animate-none"></span>
                        <span class="size-1.5 animate-bounce rounded-full bg-brand [animation-delay:0.2s] motion-reduce:animate-none"></span>
                        <span class="size-1.5 animate-bounce rounded-full bg-brand [animation-delay:0.4s] motion-reduce:animate-none"></span>
                    </span>
                    {{ $name }} is reading your records
                </p>
            </div>
        </div>

        {{-- What went wrong, in plain words, with a way forward --}}
        <div x-show="banner" x-cloak role="alert" data-owner-ask-banner x-bind:data-kind="banner?.kind"
            x-bind:class="banner?.kind === 'calm' ? 'border-app-border bg-neutral-100 text-app-text-2 dark:bg-white/5' : 'border-status-red/30 bg-status-red-bg text-[#991B1B]'"
            class="flex flex-wrap items-center gap-2 border-t px-3 py-2 text-sm sm:px-4">
            <x-ui.icon name="info" :size="18" />
            <p class="min-w-0 flex-1 basis-40" x-text="banner?.text"></p>
            <button type="button" x-show="!!banner?.retry" x-on:click="runRetry()" class="min-h-11 rounded-control px-2 font-semibold underline focus-ring">Try again</button>
            <button type="button" x-show="banner?.kind === 'session'" x-on:click="window.location.reload()" class="min-h-11 rounded-control px-2 font-semibold underline focus-ring">Reload the page</button>
            <button type="button" x-on:click="banner = null" aria-label="Dismiss this message" class="grid size-11 place-items-center rounded-control focus-ring"><x-ui.icon name="close" :size="18" /></button>
        </div>

        <form x-on:submit.prevent="submit()" class="border-t border-app-border bg-neutral-100/80 p-2 dark:bg-white/5 sm:p-3">
            <div class="flex items-end gap-1.5 rounded-control border border-app-border bg-app-card p-1 focus-within:border-brand focus-within:shadow-[0_0_0_3px_rgba(198,40,40,0.12)]">
                <textarea x-ref="input" x-model="draft" x-on:keydown="onKeydown($event)" x-on:input="resize()" rows="1" maxlength="{{ (int) config('chat.message_max_chars') }}"
                    aria-label="Your question" placeholder="Ask about your shop" x-bind:disabled="waiting"
                    class="max-h-36 min-h-11 min-w-0 flex-1 resize-none bg-transparent px-2 py-2.5 text-base leading-6 placeholder:text-app-text-2 focus:outline-none disabled:opacity-60 sm:text-sm"></textarea>
                <button type="submit" x-bind:disabled="!canSend" aria-label="Send question"
                    class="grid size-11 shrink-0 place-items-center rounded-control bg-brand text-white transition-[background-color,transform] duration-150 hover:bg-brand-deep active:scale-95 disabled:cursor-not-allowed disabled:opacity-50 focus-ring motion-reduce:transition-none motion-reduce:active:scale-100">
                    <x-ui.icon name="arrow_upward" />
                </button>
            </div>
            <p class="mt-1 hidden px-1 text-[11px] text-app-text-2 sm:block">Answers come only from your records. Shift + Enter for a new line.</p>
        </form>
    </section>
</div>
