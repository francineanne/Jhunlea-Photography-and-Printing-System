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
        {{-- In an open studio thread the launcher is just the avatar, lifted above the reply box so it never covers Send --}}
        @php($inThread = request()->routeIs('owner.messages.show'))
        <button type="button" x-ref="launcher" x-show="!open" x-on:click="show()" data-owner-ask-launcher x-cloak title="Ask {{ $name }}"
            @class([
                'fixed right-4 z-30 flex min-h-11 items-center gap-2.5 rounded-full border border-app-border bg-app-card p-1.5 shadow-[0_12px_24px_-6px_rgba(23,23,23,0.18),0_4px_8px_-2px_rgba(23,23,23,0.08)] transition-transform duration-[280ms] ease-brand hover:scale-[1.02] focus-ring motion-reduce:transition-none motion-reduce:hover:scale-100 sm:right-6',
                'bottom-[max(1.5rem,env(safe-area-inset-bottom))] pr-4' => ! $inThread,
                'bottom-24 sm:right-9' => $inThread,
            ])
            aria-label="Open Ask {{ $name }}, the AI assistant for your shop's records">
            <x-chat.avatar who="lumi" size="md" decorative />
            <span @class(['text-left leading-tight', 'hidden' => $inThread]) aria-hidden="true">
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

        {{-- One row, the same header as the customer chat: avatar, name, one "AI" pill, one truncating line, quiet icons --}}
        <header class="flex items-center gap-2.5 border-b border-app-border bg-neutral-100/80 px-3 py-2 dark:bg-white/5 sm:px-4">
            <x-chat.avatar who="lumi" size="md" />
            <div class="min-w-0 flex-1">
                <p class="flex min-w-0 items-center gap-1.5">
                    <span class="truncate font-semibold">Ask {{ $name }}</span>
                    <span class="shrink-0 whitespace-nowrap rounded bg-brand/10 px-1.5 py-px text-[10px] font-bold uppercase tracking-wider text-brand dark:bg-[#ffb4ac]/15 dark:text-[#ffb4ac]">AI</span>
                </p>
                <p class="truncate text-xs text-app-text-2" title="Reads your records; it never changes anything">Reads your records; it never changes anything</p>
            </div>
            <div class="flex shrink-0 items-center">
                <button type="button" x-on:click="newQuestion()" x-bind:disabled="waiting" title="New question" class="{{ $action }} text-app-text-2 hover:bg-black/5 hover:text-app-text disabled:opacity-50 dark:hover:bg-white/10" data-owner-ask-new>
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

        <div x-ref="log" role="log" aria-live="polite" aria-label="Questions and answers" tabindex="0" class="chat-scroll flex min-h-0 flex-1 flex-col overflow-y-auto px-3 pb-3 pt-4 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand/40 sm:p-4">
            {{-- Bottom anchor (7H.1): the greeting and its chips, or a short conversation, rest on the message box --}}
            <div class="mt-auto" aria-hidden="true"></div>

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
            <div x-show="showChips" x-cloak class="flex flex-col" data-owner-ask-chips>
                <div class="flex max-w-[85%] items-start gap-2 self-start">
                    <x-chat.avatar who="lumi" />
                    <p class="rounded-card rounded-tl-md bg-neutral-100 px-3.5 py-2.5 text-sm leading-relaxed dark:bg-white/10">Hi! I'm {{ $name }}, an AI assistant. Ask me about payments, unpaid balances, orders, bookings, the schedule, customers or messages. I only read your records; I never change anything.</p>
                </div>
                <x-chat.prompts :prompts="config('assistant.owner.suggested_prompts')" click="ask(%s)" marker="data-owner-ask-chip" :max="$full ? 5 : 4" />
            </div>

            {{-- The same rhythm as <x-chat.rows>: a date line when the day changes, the avatar, name and time once per group --}}
            <template x-for="row in rows" x-bind:key="row.key">
                <div class="flex flex-col" x-bind:class="isFirst(row) ? 'mt-4 first:mt-0' : 'mt-1'">
                    <p x-show="dayLine(row)" class="mx-auto mb-4 rounded-full bg-neutral-100 px-3 py-1 font-mono text-[11px] text-app-text-2 dark:bg-white/10" x-text="dayLine(row)"></p>

                    {{-- A limit or the AI being unavailable: a calm notice --}}
                    <template x-if="row.role === 'notice'">
                        <p class="mx-auto flex max-w-[85%] items-start gap-1.5 rounded-control border border-app-border bg-app-bg/60 px-3 py-2 text-left text-xs leading-relaxed text-app-text-2 [overflow-wrap:anywhere] dark:bg-white/5" data-owner-ask-notice x-bind:data-notice="row.notice">
                            <x-ui.icon name="schedule" :size="14" class="mt-px shrink-0 text-brand dark:text-[#ffb4ac]" /><span x-text="row.text"></span>
                        </p>
                    </template>

                    <template x-if="row.role !== 'notice'">
                        <div class="chat-bubble-in flex max-w-[85%] items-start gap-2" x-bind:class="row.role === 'owner' ? 'flex-row-reverse self-end' : 'self-start'" x-bind:data-owner-ask-row="row.role">
                            <div class="w-8 shrink-0">
                                <template x-if="isFirst(row)">
                                    <div>
                                        <x-chat.avatar who="lumi" x-show="row.role === 'lumi'" />
                                        <span x-show="row.role !== 'lumi'" class="material-symbols-outlined grid size-8 shrink-0 select-none place-items-center rounded-full bg-neutral-200 text-[16px] text-app-text-2 dark:bg-white/10" aria-hidden="true">person</span>
                                    </div>
                                </template>
                            </div>

                            <div class="flex min-w-0 flex-col gap-1" x-bind:class="row.role === 'owner' ? 'items-end' : 'items-start'">
                                <p x-show="isFirst(row)" class="flex flex-wrap items-center gap-x-1.5 text-xs" x-bind:class="row.role === 'owner' ? 'justify-end' : ''">
                                    <span class="font-semibold" x-text="row.role === 'owner' ? 'You' : @js($name)"></span>
                                    <span x-show="row.role === 'lumi'" class="rounded bg-brand/10 px-1 py-px text-[9px] font-bold uppercase tracking-wider text-brand dark:bg-[#ffb4ac]/15 dark:text-[#ffb4ac]">AI</span>
                                    {{-- The backup provider wrote this answer; which provider is never shown --}}
                                    <span x-show="row.role === 'lumi' && row.via_backup" x-cloak title="{{ $liteText }}" tabindex="0" data-owner-ask-lite
                                        class="inline-flex items-center gap-0.5 whitespace-nowrap rounded-full bg-neutral-100 px-1.5 py-px text-[10px] font-semibold text-app-text-2 focus-ring dark:bg-white/10"><span class="material-symbols-outlined select-none text-[12px] leading-none" aria-hidden="true">bolt</span>{{ $liteName }}<span class="sr-only">: {{ $liteText }}</span></span>
                                    <span class="font-mono text-[11px] text-app-text-2" x-show="row.time" x-text="clock(row)" x-bind:title="row.time"></span>
                                </p>

                                <div class="whitespace-pre-wrap rounded-card px-3.5 py-2.5 text-sm leading-relaxed [overflow-wrap:anywhere]"
                                    x-bind:class="row.role === 'owner' ? 'bg-brand-deep text-white ' + (isFirst(row) ? 'rounded-tr-md' : '') : 'bg-neutral-100 text-app-text dark:bg-white/10 ' + (isFirst(row) ? 'rounded-tl-md' : '')"
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

            <x-chat.typing :text="$name.' is reading your records'" x-show="waiting" x-cloak data-owner-ask-typing />
        </div>

        {{-- What went wrong, in plain words, with a way forward --}}
        <x-chat.banner data-owner-ask-banner />

        <form x-on:submit.prevent="submit()" class="border-t border-app-border px-2 pb-1.5 pt-2 sm:px-3">
            <x-chat.composer-box label="Your question" send-label="Send question" placeholder="Ask about your shop" disabled-bind="waiting" />
            <p class="mt-0.5 hidden min-h-6 items-center px-1 text-[11px] text-app-text-2 sm:flex">Answers come only from your records. Shift + Enter for a new line.</p>
        </form>
    </section>
</div>
