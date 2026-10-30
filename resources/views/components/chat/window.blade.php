@props(['mode' => 'compact', 'tab' => 'ask', 'about' => null])

{{--
    The customer chat (Phase 7C): the floating launcher and panel (compact) and the full page (full) are this one component
    and the one Alpine component `chatWindow` (resources/js/chat.js). Guests get Ask Lumi in memory only; the Studio tab
    asks them to sign in. Everything from a message, a name or a card is drawn with x-text, never as HTML.
--}}
@php
    $full = $mode === 'full';
    $user = rescue(fn () => auth()->user(), null, false);
    $guest = $user === null;
    $name = config('assistant.name');
    $thread = 999999999;

    $config = [
        'mode' => $mode,
        'guest' => $guest,
        'tab' => $guest ? 'ask' : $tab,
        'about' => $about,
        'name' => $name,
        'pageUrl' => $guest ? null : route('customer.assistant.page'),
        'intro' => config('chat.messages.lumi_intro'),
        'maxChars' => (int) config('chat.message_max_chars'),
        'urls' => $guest ? ['guest' => route('assistant.guest')] : [
            'studio' => route('customer.messages.studio'),
            'unread' => route('customer.messages.unread'),
            'thread' => route('customer.messages.show', $thread),
            'lumi' => route('customer.messages.lumi', $thread),
            'read' => route('customer.messages.read', $thread),
            'assistantIndex' => route('customer.assistant.index'),
            'assistantStore' => route('customer.assistant.store'),
            'handoff' => route('customer.assistant.handoff-draft', $thread),
        ],
        'texts' => [
            'you' => 'You',
            'generic' => 'Something went wrong. Please try again.',
            'offline' => 'You seem to be offline. Check your connection and try again.',
            'expired' => config('chat.messages.session_expired'),
            'signedOut' => 'You have been signed out. Please sign in again to continue.',
        ],
    ];

    $label = $full ? 'hidden md:inline' : 'sr-only';
    $action = 'inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center gap-1.5 rounded-control px-2.5 text-sm font-semibold transition-colors duration-150 focus-ring';
@endphp

<div x-data="chatWindow(@js($config))" @if (! $full) x-on:keydown.escape.window="close()" @endif {{ $attributes->class($full ? 'h-full' : '') }} data-chat-window="{{ $mode }}">
    @unless ($full)
        <button type="button" x-ref="launcher" x-show="!open" x-on:click="show()" data-chat-launcher
            class="fixed bottom-[max(1.5rem,env(safe-area-inset-bottom))] right-4 z-30 flex min-h-11 items-center gap-2.5 rounded-full border border-app-border bg-app-card py-1.5 pl-1.5 pr-4 shadow-[0_12px_24px_-6px_rgba(23,23,23,0.18),0_4px_8px_-2px_rgba(23,23,23,0.08)] transition-transform duration-[280ms] ease-brand hover:scale-[1.02] focus-ring sm:right-6 {{ request()->routeIs('packages.index') ? 'max-md:bottom-40 md:bottom-28' : '' }}"
            aria-label="Open chat with {{ $name }}, our AI assistant">
            <span class="relative grid size-9 shrink-0 place-items-center rounded-full bg-brand text-white shadow-sm" aria-hidden="true">
                <x-ui.icon name="auto_awesome" />
            </span>
            <span class="text-left leading-tight" aria-hidden="true">
                <span class="block text-sm font-semibold">Ask {{ $name }}</span>
                <span class="block text-[10px] font-bold uppercase tracking-wider text-brand dark:text-[#ffb4ac]">AI assistant</span>
            </span>
            <span x-show="unread > 0" x-cloak class="absolute -right-1 -top-1 grid h-6 min-w-6 place-items-center rounded-full bg-brand px-1.5 text-xs font-bold text-white ring-2 ring-app-bg" x-text="unread > 99 ? '99+' : unread"></span>
            <span x-show="unread > 0" x-cloak class="sr-only" x-text="unread + ' new in your studio messages'"></span>
        </button>
    @endunless

    <section x-ref="panel" @unless ($full) x-show="open" x-cloak x-transition:enter="transition duration-[280ms] ease-brand" x-transition:enter-start="translate-y-4 opacity-0" x-transition:enter-end="translate-y-0 opacity-100" x-on:keydown.tab="trap($event)" @endunless
        aria-label="Chat with {{ $name }} and the studio"
        @class([
            'flex flex-col overflow-hidden',
            'h-full rounded-card border border-app-border bg-app-card shadow-sm dark:shadow-none' => $full,
            'chat-glass chat-sheet fixed inset-x-0 z-[45] sm:inset-x-auto sm:bottom-6 sm:right-6 sm:h-[min(42rem,calc(100dvh-3rem))] sm:w-[26.5rem] sm:rounded-card sm:border sm:border-app-border sm:shadow-[0_24px_48px_-12px_rgba(23,23,23,0.25)]' => ! $full,
        ])>

        {{-- Header: who this is, the AI badge, and the actions --}}
        <header class="flex items-center gap-3 bg-neutral-100/80 p-3 dark:bg-white/5 sm:px-4">
            <span class="grid size-10 shrink-0 place-items-center rounded-card bg-brand text-white shadow-sm" aria-hidden="true"><x-ui.icon name="auto_awesome" :size="22" /></span>
            <div class="min-w-0 flex-1">
                <p class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                    <span class="font-semibold" x-text="tab === 'ask' ? name : 'Studio'"></span>
                    <span x-show="tab === 'ask'" class="rounded bg-brand/10 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-brand dark:bg-[#ffb4ac]/15 dark:text-[#ffb4ac]">AI assistant</span>
                </p>
                <p class="text-xs text-app-text-2" x-show="tab === 'ask'">About Jhunlea's services</p>
                <p class="text-xs text-app-text-2" x-show="tab === 'studio'" x-cloak>Your messages with the studio</p>
            </div>
            <div class="flex shrink-0 items-center gap-1">
                <button type="button" x-show="tab === 'ask'" x-on:click="newConversation()" title="New conversation" class="{{ $action }} text-app-text hover:bg-black/5 dark:hover:bg-white/10">
                    <x-ui.icon name="restart_alt" /><span class="{{ $label }}">New conversation</span>
                </button>
                <button type="button" x-show="tab === 'ask'" x-on:click="contactOwner()" title="Contact the owner" class="{{ $action }} bg-ink text-white hover:bg-neutral-800 dark:bg-white dark:text-ink dark:hover:bg-neutral-200">
                    <x-ui.icon name="support_agent" /><span class="{{ $label }}">Contact the owner</span>
                </button>
                @unless ($full || $guest)
                    <a href="{{ route('customer.assistant.page') }}" x-bind:href="pageUrl + '?tab=' + tab" aria-label="Open the full chat page" title="Open the full chat page" class="{{ $action }} text-app-text-2 hover:bg-black/5 hover:text-app-text max-sm:hidden dark:hover:bg-white/10">
                        <x-ui.icon name="open_in_full" :size="18" />
                    </a>
                @endunless
                @unless ($full)
                    <button type="button" x-on:click="close()" aria-label="Close chat" title="Close chat" class="{{ $action }} text-app-text-2 hover:bg-black/5 hover:text-app-text dark:hover:bg-white/10">
                        <x-ui.icon name="close" />
                    </button>
                @endunless
            </div>
        </header>

        {{-- The two conversations --}}
        <div role="tablist" aria-label="Conversations" class="grid grid-cols-2 gap-1 border-b border-app-border bg-neutral-100/80 px-3 pb-2 dark:bg-white/5 sm:px-4">
            <button type="button" role="tab" x-bind:aria-selected="tab === 'ask'" x-on:click="switchTab('ask')"
                x-bind:class="tab === 'ask' ? 'bg-app-card shadow-sm dark:bg-white/15' : 'text-app-text-2 hover:bg-black/5 dark:hover:bg-white/5'"
                class="inline-flex min-h-11 items-center justify-center gap-1.5 rounded-control text-sm font-semibold transition-colors duration-150 focus-ring">
                <x-ui.icon name="auto_awesome" :size="18" />Ask {{ $name }}
            </button>
            <button type="button" role="tab" x-bind:aria-selected="tab === 'studio'" x-on:click="switchTab('studio')"
                x-bind:class="tab === 'studio' ? 'bg-app-card shadow-sm dark:bg-white/15' : 'text-app-text-2 hover:bg-black/5 dark:hover:bg-white/5'"
                class="inline-flex min-h-11 items-center justify-center gap-1.5 rounded-control text-sm font-semibold transition-colors duration-150 focus-ring">
                <x-ui.icon name="storefront" :size="18" />Studio
                <span x-show="unread > 0 && tab !== 'studio'" x-cloak class="grid h-5 min-w-5 place-items-center rounded-full bg-brand px-1 text-[11px] font-bold text-white" x-text="unread > 99 ? '99+' : unread"></span>
            </button>
        </div>

        {{-- What Lumi is and is not --}}
        <div x-show="tab === 'ask' && !policyHidden" class="flex items-start gap-2 border-b border-app-border px-3 py-2 sm:px-4">
            <x-ui.icon name="gavel" :size="18" class="mt-0.5 text-brand dark:text-[#ffb4ac]" />
            <p class="min-w-0 flex-1 text-xs leading-relaxed text-app-text-2">
                <span class="font-semibold text-app-text">{{ $name }} is an AI assistant.</span> It answers only about Jhunlea's services and cannot confirm bookings, set final prices or record payments. The studio confirms everything.
            </p>
            <button type="button" x-on:click="dismissPolicy()" aria-label="Dismiss this notice" class="-my-1 -mr-1 grid size-11 shrink-0 place-items-center rounded-control text-app-text-2 hover:text-app-text focus-ring">
                <x-ui.icon name="close" :size="18" />
            </button>
        </div>

        {{-- The studio's status, only what the server says --}}
        <div x-show="tab === 'studio' && !signInNeeded && (studioState?.owner_away || studioState?.waiting_for_studio)" x-cloak class="space-y-1.5 border-b border-app-border px-3 py-2 text-xs sm:px-4" data-chat-status>
            <p x-show="studioState?.owner_away" class="flex items-start gap-2 text-app-text-2">
                <x-ui.icon name="schedule" :size="16" class="mt-px text-brand dark:text-[#ffb4ac]" /><span class="min-w-0" x-text="studioState?.away_notice"></span>
            </p>
            <p x-show="studioState?.waiting_for_studio" class="inline-flex items-center gap-1.5 rounded-full bg-status-amber-bg px-2.5 py-1 font-semibold text-[#92400E]">
                <x-ui.icon name="hourglass_top" :size="14" />Waiting for the studio
            </p>
        </div>

        {{-- A guest's Studio tab: no conversation exists until they sign in --}}
        @if ($guest)
            <div x-show="signInNeeded" x-cloak class="flex flex-1 flex-col items-center justify-center gap-3 p-6 text-center">
                <span class="grid size-12 place-items-center rounded-card bg-neutral-100 text-brand dark:bg-white/10 dark:text-[#ffb4ac]" aria-hidden="true"><x-ui.icon name="lock" :size="24" /></span>
                <p class="font-semibold">Sign in to message the studio</p>
                <p class="max-w-xs text-sm text-app-text-2">Your messages with the owner are kept in your account, so you need to sign in first.</p>
                <x-ui.button :href="route('login')">Log in</x-ui.button>
            </div>
        @endif

        {{-- The conversation --}}
        <div x-show="!signInNeeded" x-ref="log" role="log" aria-live="polite" aria-label="Messages" tabindex="0" class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-3 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand/40 sm:p-4">
            <button type="button" x-show="thread.state?.has_more_before" x-cloak x-on:click="loadEarlier()" x-bind:disabled="thread.earlier"
                class="mx-auto inline-flex min-h-11 items-center gap-1.5 rounded-full border border-app-border px-4 text-sm font-semibold text-app-text-2 hover:bg-black/5 disabled:opacity-60 focus-ring dark:hover:bg-white/5">
                <x-ui.icon name="expand_less" :size="18" />Load earlier
            </button>

            <p x-show="thread.loading" x-cloak class="m-auto flex items-center gap-2 text-sm text-app-text-2"><x-ui.aperture-spinner :size="20" />Loading the conversation</p>

            <template x-for="row in rows" x-bind:key="row.key">
                <div class="flex flex-col">
                    <template x-if="row.type === 'date'">
                        <p class="mx-auto rounded-full bg-neutral-100 px-3 py-1 font-mono text-[11px] text-app-text-2 dark:bg-white/10" x-text="row.text"></p>
                    </template>

                    <template x-if="row.type === 'notice'">
                        <p class="mx-auto max-w-[90%] whitespace-pre-wrap rounded-control bg-neutral-100 px-3 py-2 text-center text-xs text-app-text-2 [overflow-wrap:anywhere] dark:bg-white/5" data-chat-notice x-text="row.message.body"></p>
                    </template>

                    <template x-if="row.type === 'bubble'">
                        <div class="flex max-w-[92%] items-start gap-2" x-bind:class="row.message.mine ? 'flex-row-reverse self-end' : 'self-start'" x-bind:data-chat-sender="row.message.sender_type">
                            <span class="material-symbols-outlined grid size-8 shrink-0 select-none place-items-center rounded-full text-[16px] text-white shadow-sm" aria-hidden="true"
                                x-bind:class="row.message.sender_type === 'assistant' ? 'bg-brand' : (row.message.sender_type === 'owner' ? 'bg-neutral-700' : 'bg-ink dark:bg-neutral-600')"
                                x-text="row.message.sender_type === 'assistant' ? 'auto_awesome' : (row.message.sender_type === 'owner' ? 'storefront' : 'person')"></span>

                            <div class="flex min-w-0 flex-col gap-1" x-bind:class="row.message.mine ? 'items-end' : 'items-start'">
                                <p class="flex flex-wrap items-center gap-x-1.5 text-xs" x-bind:class="row.message.mine ? 'justify-end' : ''">
                                    <span class="font-semibold" x-text="row.message.sender_label"></span>
                                    <span x-show="row.message.sender_type === 'assistant'" class="rounded bg-brand/10 px-1 py-px text-[9px] font-bold uppercase tracking-wider text-brand dark:bg-[#ffb4ac]/15 dark:text-[#ffb4ac]">AI</span>
                                    <span class="font-mono text-[11px] text-app-text-2" x-show="row.time" x-text="row.time"></span>
                                </p>

                                <p x-show="row.message.about" class="inline-flex items-center gap-1 rounded-full border border-app-border px-2 py-0.5 font-mono text-[11px] text-app-text-2">
                                    <x-ui.icon name="receipt_long" :size="14" /><span x-text="'About ' + row.message.about?.reference"></span>
                                </p>

                                <div class="whitespace-pre-wrap rounded-card px-3 py-2 text-sm [overflow-wrap:anywhere]"
                                    x-bind:class="row.message.mine ? 'rounded-tr-none bg-brand-deep text-white' : 'rounded-tl-none bg-neutral-100 text-app-text dark:bg-white/10'"
                                    x-text="row.message.body"></div>

                                <p x-show="row.message.via_lumi" class="text-[11px] text-app-text-2">Sent from your Ask Lumi chat</p>
                                <p x-show="row.message.status === 'sending'" class="text-[11px] text-app-text-2">Sending</p>
                                <button type="button" x-show="row.message.status === 'failed'" x-on:click="retryMessage(row.message)"
                                    class="inline-flex min-h-11 items-center gap-1 rounded text-xs font-semibold text-status-red hover:underline focus-ring dark:text-[#ffb4ac]">
                                    <x-ui.icon name="error" :size="16" />Failed. Tap to retry
                                </button>

                                {{-- Recommendation cards: only what the server built from the catalog --}}
                                <template x-for="card in row.message.cards ?? []" x-bind:key="card.type + card.id">
                                    <article class="mt-1 w-full rounded-card border border-app-border bg-app-card p-3 shadow-sm dark:shadow-none" data-chat-card>
                                        <h3 class="font-semibold [overflow-wrap:anywhere]" x-text="card.name"></h3>
                                        <p x-show="card.price" class="mt-0.5 text-sm font-bold tabular-nums text-brand dark:text-[#ffb4ac]" x-text="card.price"></p>
                                        <p x-show="card.summary" class="mt-1 text-xs text-app-text-2 [overflow-wrap:anywhere]" x-text="card.summary"></p>
                                        <ul x-show="card.inclusions?.length" class="mt-2 space-y-0.5 text-xs text-app-text-2">
                                            <template x-for="item in card.inclusions ?? []" x-bind:key="item">
                                                <li class="flex items-start gap-1.5"><x-ui.icon name="check" :size="14" class="mt-px text-brand dark:text-[#ffb4ac]" /><span class="min-w-0 [overflow-wrap:anywhere]" x-text="item"></span></li>
                                            </template>
                                        </ul>
                                        <a x-bind:href="card.url" class="mt-2 inline-flex min-h-11 items-center gap-1 rounded-control text-sm font-semibold text-brand hover:underline focus-ring dark:text-[#ffb4ac]">View details<x-ui.icon name="arrow_forward" :size="16" /></a>
                                    </article>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
            </template>

            {{-- Suggestions for a conversation nobody has written in yet --}}
            <div x-show="showPrompts" x-cloak class="flex flex-col gap-1.5">
                <p class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Try asking</p>
                <div class="flex flex-wrap gap-1.5">
                    @foreach (config('chat.suggested_prompts') as $prompt)
                        <button type="button" x-on:click="drafts.ask = @js($prompt); submit()" class="min-h-11 rounded-full bg-neutral-100 px-3 py-1.5 text-left text-sm transition-colors duration-150 hover:bg-neutral-200 focus-ring dark:bg-white/10 dark:hover:bg-white/15">{{ $prompt }}</button>
                    @endforeach
                </div>
            </div>

            <div x-show="thread.typing" x-cloak class="flex items-center gap-2" data-chat-typing>
                <span class="grid size-8 shrink-0 place-items-center rounded-full bg-brand/15 text-brand dark:text-[#ffb4ac]" aria-hidden="true"><x-ui.icon name="auto_awesome" :size="16" /></span>
                <p class="flex items-center gap-2 rounded-full bg-neutral-100 px-3 py-2 text-xs text-app-text-2 dark:bg-white/10">
                    <span class="flex items-center gap-1" aria-hidden="true">
                        <span class="size-1.5 animate-bounce rounded-full bg-brand"></span>
                        <span class="size-1.5 animate-bounce rounded-full bg-brand [animation-delay:0.2s]"></span>
                        <span class="size-1.5 animate-bounce rounded-full bg-brand [animation-delay:0.4s]"></span>
                    </span>
                    {{ $name }} is typing
                </p>
            </div>
        </div>

        {{-- Anything that went wrong, in plain words, with a way forward --}}
        <div x-show="banner" x-cloak role="alert" class="flex flex-wrap items-center gap-2 border-t border-status-red/30 bg-status-red-bg px-3 py-2 text-sm text-[#991B1B] sm:px-4" data-chat-banner>
            <x-ui.icon name="error" :size="18" />
            <p class="min-w-0 flex-1 basis-40" x-text="banner?.text"></p>
            {{-- !!: Alpine calls an expression that evaluates to a function, which here would run the retry on every render --}}
            <button type="button" x-show="!!banner?.retry" x-on:click="runRetry()" class="min-h-11 rounded-control px-2 font-semibold underline focus-ring">Try again</button>
            <button type="button" x-show="banner?.kind === 'full'" x-on:click="newConversation()" class="min-h-11 rounded-control px-2 font-semibold underline focus-ring">Start a new conversation</button>
            <button type="button" x-show="banner?.kind === 'session'" x-on:click="window.location.reload()" class="min-h-11 rounded-control px-2 font-semibold underline focus-ring">Reload the page</button>
            <button type="button" x-on:click="banner = null" aria-label="Dismiss this message" class="grid size-11 place-items-center rounded-control focus-ring"><x-ui.icon name="close" :size="18" /></button>
        </div>

        {{-- The composer --}}
        <form x-show="!signInNeeded" x-on:submit.prevent="submit()" class="border-t border-app-border bg-neutral-100/80 p-2 dark:bg-white/5 sm:p-3">
            <p x-show="tab === 'studio' && about" x-cloak class="mb-1.5 inline-flex items-center gap-1 rounded-full border border-app-border bg-app-card px-2 py-1 font-mono text-[11px]" data-chat-about>
                <x-ui.icon name="receipt_long" :size="14" /><span x-text="'About ' + about?.reference"></span>
                <button type="button" x-on:click="about = null" aria-label="Remove this reference" class="-my-3 grid size-11 place-items-center rounded-full focus-ring"><x-ui.icon name="close" :size="14" /></button>
            </p>
            <p x-show="tab === 'studio' && fromAssistant" x-cloak class="mb-1.5 flex items-center gap-1 text-xs text-app-text-2">
                <x-ui.icon name="auto_awesome" :size="14" />Sent from your Ask Lumi chat. Please check the text before you send it.
            </p>
            <div class="flex items-end gap-1.5 rounded-control border border-app-border bg-app-card p-1 focus-within:border-brand focus-within:shadow-[0_0_0_3px_rgba(198,40,40,0.12)]">
                <textarea x-ref="input" x-model="draft" x-on:keydown="onKeydown($event)" x-on:input="resize()" rows="1" maxlength="{{ (int) config('chat.message_max_chars') }}"
                    aria-label="Your message" x-bind:placeholder="tab === 'ask' ? 'Ask about our services' : 'Write to the studio'"
                    class="max-h-36 min-h-11 min-w-0 flex-1 resize-none bg-transparent px-2 py-2.5 text-base leading-6 placeholder:text-app-text-2 focus:outline-none sm:text-sm"></textarea>
                <button type="submit" x-bind:disabled="!canSend" aria-label="Send message"
                    class="grid size-11 shrink-0 place-items-center rounded-control bg-brand text-white transition-[background-color,transform] duration-150 hover:bg-brand-deep active:scale-95 disabled:cursor-not-allowed disabled:opacity-50 focus-ring">
                    <x-ui.icon name="arrow_upward" />
                </button>
            </div>
            <div class="mt-1 flex items-center justify-between gap-2 px-1 text-[11px] text-app-text-2">
                <span x-show="guest && tab === 'ask'" x-cloak>Signing in saves this conversation. <a href="{{ route('login') }}" class="inline-flex min-h-11 min-w-11 items-center justify-center rounded font-semibold text-brand underline focus-ring dark:text-[#ffb4ac] sm:min-h-0 sm:min-w-0">Log in</a></span>
                <span class="hidden sm:inline" x-show="!guest">Shift + Enter for a new line</span>
                <span x-show="showCounter" x-cloak class="ml-auto font-mono tabular-nums" aria-live="polite" x-text="draft.length + ' / ' + maxChars"></span>
            </div>
        </form>
    </section>
</div>
