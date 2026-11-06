@props(['mode' => 'compact', 'tab' => null, 'about' => null, 'draft' => null])

{{--
    The customer chat (Phase 7C): the floating launcher and panel (compact) and the full page (full) are this one component
    and the one Alpine component `chatWindow` (resources/js/chat.js). Guests get Ask Lumi in memory only; the Studio tab
    asks them to sign in. Everything from a message, a name or a card is drawn with x-text, never as HTML.
    7F: with no `tab` given and no remembered choice, a chooser ("Ask Lumi (AI)" or "Message the studio (a person)") comes
    first. A page opens the Studio tab with a typed draft through the window event `chat:studio` ({ draft, about }); nothing
    is ever sent for the customer.
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
        'tab' => $guest ? 'ask' : ($tab ?? 'ask'),
        // A tab the page asked for skips the chooser
        'chosen' => $tab !== null,
        'about' => $about,
        'draft' => $guest ? null : $draft,
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
            'rateLimited' => config('chat.messages.rate_limited'),
        ],
    ];

    $label = $full ? 'hidden md:inline' : 'sr-only';
    $action = 'inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center gap-1.5 rounded-control px-2.5 text-sm font-semibold transition-colors duration-150 focus-ring';
@endphp

<div x-data="chatWindow(@js($config))" @if (! $full) x-on:keydown.escape.window="close()" @endif {{ $attributes->class($full ? 'h-full' : '') }} data-chat-window="{{ $mode }}">
    @unless ($full)
        <button type="button" x-ref="launcher" x-show="!open" x-on:click="show()" data-chat-launcher
            class="fixed bottom-[max(1.5rem,env(safe-area-inset-bottom))] right-4 z-30 flex min-h-11 items-center gap-2.5 rounded-full border border-app-border bg-app-card py-1.5 pl-1.5 pr-4 shadow-[0_12px_24px_-6px_rgba(23,23,23,0.18),0_4px_8px_-2px_rgba(23,23,23,0.08)] transition-transform duration-[280ms] ease-brand hover:scale-[1.02] focus-ring sm:right-6 {{ request()->routeIs('packages.index') ? 'max-md:bottom-40 md:bottom-28' : '' }}"
            aria-label="Chat with {{ $name }} or the studio">
            <x-ui.logo-mark class="shadow-sm" />
            <span class="text-left leading-tight" aria-hidden="true">
                <span class="block text-sm font-semibold">Chat</span>
                <span class="block text-[10px] font-bold uppercase tracking-wider text-brand dark:text-[#ffb4ac]">{{ $name }} AI or the studio</span>
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

        {{-- Header: one row. Who this is, one small pill, one short line that truncates; the secondary actions live in
             the "more" menu, so Expand and Close stay visible and nothing wraps --}}
        <header class="flex items-center gap-2.5 border-b border-app-border bg-neutral-100/80 px-3 py-2 dark:bg-white/5 sm:px-4">
            <x-ui.logo-mark class="shadow-sm" x-show="choosing" />
            <x-chat.avatar who="lumi" size="md" x-show="!choosing && tab === 'ask'" />
            <x-chat.avatar who="studio" size="md" x-show="!choosing && tab === 'studio'" x-cloak />
            <div class="min-w-0 flex-1">
                <p class="flex min-w-0 items-center gap-1.5">
                    <span class="truncate font-semibold" x-text="choosing ? 'Chat' : (tab === 'ask' ? name : 'Studio')">Chat</span>
                    <span x-show="!choosing && tab === 'ask'" class="shrink-0 whitespace-nowrap rounded bg-brand/10 px-1.5 py-px text-[10px] font-bold uppercase tracking-wider text-brand dark:bg-[#ffb4ac]/15 dark:text-[#ffb4ac]">AI</span>
                    <span x-show="!choosing && tab === 'studio'" x-cloak class="shrink-0 whitespace-nowrap rounded bg-neutral-200 px-1.5 py-px text-[10px] font-bold uppercase tracking-wider text-app-text dark:bg-white/15">Person</span>
                </p>
                <p class="truncate text-xs text-app-text-2" x-show="choosing">{{ $name }} AI or the studio</p>
                <p class="truncate text-xs text-app-text-2" x-show="!choosing && tab === 'ask'" x-cloak>AI assistant for Jhunlea's services</p>
                {{-- Only a coarse label from the server's owner_away and lumi_active: never a time or what the owner did --}}
                <p class="truncate text-xs text-app-text-2" x-show="!choosing && tab === 'studio'" x-cloak data-chat-presence x-text="presenceText" x-bind:title="presenceText">Your messages with the studio</p>
            </div>
            <div class="flex shrink-0 items-center">
                {{-- More: Switch, New conversation, Contact the owner. Esc closes it and returns focus to this button --}}
                <div class="relative" x-show="!choosing" x-cloak x-on:click.outside="menuOpen = false" x-on:keydown.escape.stop="closeMenu()">
                    <button type="button" x-ref="menuButton" x-on:click="toggleMenu()" aria-haspopup="menu" x-bind:aria-expanded="menuOpen" aria-label="More options" title="More options"
                        class="{{ $action }} px-0 text-app-text-2 hover:bg-black/5 hover:text-app-text dark:hover:bg-white/10" data-chat-more>
                        <x-ui.icon name="more_vert" />
                    </button>
                    <div x-show="menuOpen" x-cloak x-ref="menu" role="menu" aria-label="More options" x-on:keydown.down.prevent="moveMenu(1)" x-on:keydown.up.prevent="moveMenu(-1)"
                        x-transition:enter="transition duration-150 ease-brand motion-reduce:transition-none" x-transition:enter-start="-translate-y-1 opacity-0"
                        class="absolute right-0 top-full z-20 mt-1 w-64 rounded-card border border-app-border bg-app-card p-1 shadow-[0_12px_24px_-6px_rgba(23,23,23,0.18)] dark:shadow-none">
                        <button type="button" role="menuitem" x-on:click="menuOpen = false; openChooser()" aria-label="Switch between {{ $name }} and the studio" data-chat-switch
                            class="flex min-h-11 w-full items-center gap-2.5 rounded-control px-3 text-left text-sm hover:bg-black/5 focus-ring dark:hover:bg-white/10">
                            <x-ui.icon name="swap_horiz" :size="18" class="text-app-text-2" />Switch: {{ $name }} or the studio
                        </button>
                        <button type="button" role="menuitem" x-show="tab === 'ask'" x-on:click="menuOpen = false; newConversation()"
                            class="flex min-h-11 w-full items-center gap-2.5 rounded-control px-3 text-left text-sm hover:bg-black/5 focus-ring dark:hover:bg-white/10">
                            <x-ui.icon name="restart_alt" :size="18" class="text-app-text-2" />New conversation
                        </button>
                        <button type="button" role="menuitem" x-show="tab === 'ask'" x-on:click="menuOpen = false; contactOwner()"
                            class="flex min-h-11 w-full items-center gap-2.5 rounded-control px-3 text-left text-sm hover:bg-black/5 focus-ring dark:hover:bg-white/10">
                            <x-ui.icon name="support_agent" :size="18" class="text-app-text-2" />Contact the owner
                        </button>
                    </div>
                </div>
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
        <div role="tablist" aria-label="Conversations" x-show="!choosing" class="grid grid-cols-2 gap-1 border-b border-app-border px-3 py-1.5 sm:px-4">
            <button type="button" role="tab" x-bind:aria-selected="tab === 'ask'" x-on:click="switchTab('ask')"
                x-bind:class="tab === 'ask' ? 'bg-neutral-100 text-app-text dark:bg-white/10' : 'text-app-text-2 hover:bg-black/5 dark:hover:bg-white/5'"
                class="inline-flex min-h-11 items-center justify-center gap-1.5 rounded-control text-sm font-semibold transition-colors duration-150 focus-ring sm:min-h-9">
                <x-ui.icon name="camera" :size="18" />Ask {{ $name }} <span class="text-[11px] font-bold text-brand dark:text-[#ffb4ac]">(AI)</span>
            </button>
            <button type="button" role="tab" x-bind:aria-selected="tab === 'studio'" x-on:click="switchTab('studio')"
                x-bind:class="tab === 'studio' ? 'bg-neutral-100 text-app-text dark:bg-white/10' : 'text-app-text-2 hover:bg-black/5 dark:hover:bg-white/5'"
                class="inline-flex min-h-11 items-center justify-center gap-1.5 rounded-control text-sm font-semibold transition-colors duration-150 focus-ring sm:min-h-9">
                <x-ui.icon name="storefront" :size="18" />Studio
                <span x-show="unread > 0 && tab !== 'studio'" x-cloak class="grid h-5 min-w-5 place-items-center rounded-full bg-brand px-1 text-[11px] font-bold text-white" x-text="unread > 99 ? '99+' : unread"></span>
            </button>
        </div>

        {{-- Who do you want to talk to? Shown first when nothing was chosen (or remembered) yet, and after "Switch" --}}
        <div x-show="choosing" class="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto p-3 sm:p-4" data-chat-chooser>
            <p class="font-display text-lg font-semibold" id="chat-chooser-{{ $mode }}">Who do you want to talk to?</p>
            <div class="flex flex-col gap-3" role="group" aria-labelledby="chat-chooser-{{ $mode }}">
                <button type="button" x-on:click="choose('ask')" x-ref="chooseAsk" data-chat-choose="ask"
                    class="group flex items-start gap-3 rounded-card border border-app-border bg-app-card p-4 text-left shadow-sm transition-[border-color,box-shadow] duration-200 hover:border-brand hover:shadow-md focus-ring dark:shadow-none">
                    <x-chat.avatar who="lumi" size="lg" decorative />
                    <span class="min-w-0">
                        <span class="block font-semibold">Ask {{ $name }} (AI)</span>
                        <span class="mt-0.5 block text-sm text-app-text-2">{{ $guest ? 'Questions and suggestions: packages, prices, hours and which package fits you.' : 'Quick answers about packages, prices, hours, and your own orders and bookings.' }}</span>
                    </span>
                </button>
                @if ($guest)
                    {{-- Guests: Lumi answers questions and suggests packages; the studio needs an account --}}
                    <div data-chat-choose="studio" class="flex items-start gap-3 rounded-card border border-app-border bg-app-card p-4 shadow-sm dark:shadow-none">
                        <x-chat.avatar who="studio" size="lg" decorative />
                        <div class="min-w-0">
                            <p class="font-semibold">Message the studio (a person)</p>
                            <p class="mt-0.5 text-sm text-app-text-2">For changes, special requests, complaints, or confirming something.</p>
                            <p class="mt-1 flex items-center gap-1 text-sm font-semibold"><x-ui.icon name="lock" :size="16" class="text-brand dark:text-[#ffb4ac]" />Log in or create an account first.</p>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <x-ui.button :href="route('login')" size="sm">Log in</x-ui.button>
                                <x-ui.button :href="route('register')" variant="outline" size="sm">Create account</x-ui.button>
                            </div>
                        </div>
                    </div>
                @else
                    <button type="button" x-on:click="choose('studio')" data-chat-choose="studio"
                        class="group flex items-start gap-3 rounded-card border border-app-border bg-app-card p-4 text-left shadow-sm transition-[border-color,box-shadow] duration-200 hover:border-brand hover:shadow-md focus-ring dark:shadow-none">
                        <x-chat.avatar who="studio" size="lg" decorative />
                        <span class="min-w-0">
                            <span class="block font-semibold">Message the studio (a person)</span>
                            <span class="mt-0.5 block text-sm text-app-text-2">For changes, special requests, complaints, or confirming something. {{ $name }} may help while the studio is away.</span>
                        </span>
                    </button>
                @endif
            </div>
        </div>

        {{-- What Lumi is and is not --}}
        {{-- Said once: shown until dismissed (remembered in this browser when storage works) --}}
        <div x-show="!choosing && tab === 'ask' && !policyHidden" class="flex items-center gap-2 border-b border-app-border bg-brand/5 py-1 pl-3 pr-1 dark:bg-white/5 sm:pl-4" data-chat-policy>
            <x-ui.icon name="info" :size="16" class="shrink-0 text-brand dark:text-[#ffb4ac]" />
            <p class="min-w-0 flex-1 py-1 text-xs leading-snug text-app-text-2">
                <span class="font-semibold text-app-text">{{ $name }} is an AI assistant.</span> It cannot confirm bookings, set final prices or record payments. The studio confirms everything.
            </p>
            <button type="button" x-on:click="dismissPolicy()" aria-label="Dismiss this notice" title="Dismiss" class="grid size-11 shrink-0 place-items-center rounded-control text-app-text-2 hover:text-app-text focus-ring sm:size-9">
                <x-ui.icon name="close" :size="16" />
            </button>
        </div>

        {{-- The studio's status, only what the server says --}}
        {{-- away_notice is null once the thread itself shows it: one away indication at a time (7D) --}}
        <div x-show="!choosing && tab === 'studio' && !signInNeeded && (studioState?.away_notice || studioState?.waiting_for_studio)" x-cloak class="space-y-1.5 border-b border-app-border px-3 py-2 text-xs sm:px-4" data-chat-status>
            <p x-show="studioState?.away_notice" class="flex items-start gap-2 text-app-text-2">
                <x-ui.icon name="schedule" :size="16" class="mt-px text-brand dark:text-[#ffb4ac]" /><span class="min-w-0" x-text="studioState?.away_notice"></span>
            </p>
            <p x-show="studioState?.waiting_for_studio" class="inline-flex items-center gap-1.5 rounded-full bg-status-amber-bg px-2.5 py-1 font-semibold text-[#92400E]">
                <x-ui.icon name="hourglass_top" :size="14" />Waiting for the studio
            </p>
        </div>

        {{-- A guest's Studio tab: no conversation exists until they sign in --}}
        @if ($guest)
            <div x-show="!choosing && signInNeeded" x-cloak class="flex flex-1 flex-col items-center justify-center gap-3 p-6 text-center">
                <span class="grid size-12 place-items-center rounded-card bg-neutral-100 text-brand dark:bg-white/10 dark:text-[#ffb4ac]" aria-hidden="true"><x-ui.icon name="lock" :size="24" /></span>
                <p class="font-semibold">Sign in to message the studio</p>
                <p class="max-w-xs text-sm text-app-text-2">Your messages with the owner are kept in your account, so you need to log in or create an account first.</p>
                <div class="flex flex-wrap justify-center gap-2">
                    <x-ui.button :href="route('login')">Log in</x-ui.button>
                    <x-ui.button :href="route('register')" variant="outline">Create account</x-ui.button>
                </div>
            </div>
        @endif

        {{-- The conversation --}}
        <div x-show="!choosing && !signInNeeded" x-ref="log" role="log" aria-live="polite" aria-label="Messages" tabindex="0" class="chat-scroll flex min-h-0 flex-1 flex-col overflow-y-auto px-3 pb-3 pt-4 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand/40 sm:p-4">
            {{-- Bottom anchor (7H.1): takes the free space above, so a short conversation (or the greeting and its chips)
                 rests on the message box; a long one scrolls as usual --}}
            <div class="mt-auto" aria-hidden="true"></div>

            <button type="button" x-show="thread.state?.has_more_before" x-cloak x-on:click="loadEarlier()" x-bind:disabled="thread.earlier"
                class="mx-auto inline-flex min-h-11 items-center gap-1.5 rounded-full border border-app-border px-4 text-sm font-semibold text-app-text-2 hover:bg-black/5 disabled:opacity-60 focus-ring dark:hover:bg-white/5">
                <x-ui.icon name="expand_less" :size="18" />Load earlier
            </button>

            <p x-show="thread.loading" x-cloak class="m-auto flex items-center gap-2 text-sm text-app-text-2"><x-ui.aperture-spinner :size="20" />Loading the conversation</p>

            <x-chat.rows />

            {{-- Suggestions for a conversation nobody has written in yet --}}
            <x-chat.prompts :prompts="config($guest ? 'chat.suggested_prompts_guest' : 'chat.suggested_prompts')" click="drafts.ask = %s; submit()" marker="data-chat-prompt"
                :max="$full ? 5 : 4" x-show="showPrompts" x-cloak data-chat-prompts />

            <x-chat.typing :text="$name.' is typing'" x-show="thread.typing" x-cloak data-chat-typing />
        </div>

        {{-- Anything that went wrong, in plain words, with a way forward --}}
        <x-chat.banner data-chat-banner>
            <button type="button" x-show="banner?.kind === 'full'" x-on:click="newConversation()" class="min-h-11 rounded-control px-2 font-semibold underline focus-ring">Start a new conversation</button>
        </x-chat.banner>

        {{-- The composer --}}
        <form x-show="!choosing && !signInNeeded" x-on:submit.prevent="submit()" class="border-t border-app-border px-2 pb-1.5 pt-2 sm:px-3">
            <p x-show="tab === 'studio' && about" x-cloak class="mb-1.5 inline-flex items-center gap-1 rounded-full border border-app-border bg-app-card px-2 py-1 font-mono text-[11px]" data-chat-about>
                <x-ui.icon name="receipt_long" :size="14" /><span x-text="'About ' + about?.reference"></span>
                <button type="button" x-on:click="about = null" aria-label="Remove this reference" class="-my-3 grid size-11 place-items-center rounded-full focus-ring"><x-ui.icon name="close" :size="14" /></button>
            </p>
            <p x-show="tab === 'studio' && fromAssistant" x-cloak class="mb-1.5 flex items-center gap-1 text-xs text-app-text-2">
                <x-ui.icon name="camera" :size="14" />Sent from your Ask Lumi chat. Please check the text before you send it.
            </p>
            <x-chat.composer-box label="Your message" send-label="Send message" placeholder-bind="tab === 'ask' ? 'Ask about our services' : 'Write to the studio'" />
            {{-- One quiet line under the box: the way to a person (Ask Lumi), the sign-in note (guests), the counter --}}
            <div class="mt-0.5 flex min-h-6 items-center justify-between gap-2 px-1 text-[11px] text-app-text-2">
                <span x-show="guest && tab === 'ask'" x-cloak>Signing in saves this conversation. <a href="{{ route('login') }}" class="inline-flex min-h-11 min-w-11 items-center justify-center rounded font-semibold text-brand underline focus-ring dark:text-[#ffb4ac] sm:min-h-6 sm:min-w-0">Log in</a></span>
                <span x-show="!guest && tab === 'ask'" data-chat-footer>Changes or special requests?
                    <button type="button" x-on:click="choose('studio')" class="inline-flex min-h-11 items-center rounded font-semibold text-brand underline focus-ring dark:text-[#ffb4ac] sm:min-h-6">Message the studio</button>
                </span>
                <span class="hidden sm:inline" x-show="!guest && tab === 'studio'" x-cloak>Shift + Enter for a new line</span>
                <span x-show="showCounter" x-cloak class="ml-auto font-mono tabular-nums" aria-live="polite" x-text="draft.length + ' / ' + maxChars"></span>
            </div>
        </form>
    </section>
</div>
