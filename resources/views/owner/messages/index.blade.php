@extends('layouts.owner')

@section('title', 'Messages | '.config('app.name'))

{{--
    The owner's studio inbox (Phase 7D, design/admin/notifications_customer_messages): the threads on the left, the open
    thread on the right (one at a time on phones and tablets). Lists, thread, assist and presence load through the JSON
    of the same addresses; nothing from a message or a name is drawn as HTML.
--}}
@php
    $thread = 999999999;
    $config = [
        'conversationId' => $conversation?->id,
        'filter' => $filter,
        'search' => $search,
        'maxChars' => (int) config('chat.message_max_chars'),
        'urls' => [
            'inbox' => route('owner.messages.index'),
            'thread' => route('owner.messages.show', $thread),
            'page' => route('owner.messages.show', $thread),
            'read' => route('owner.messages.read', $thread),
            'resolve' => route('owner.messages.resolve', $thread),
            'reopen' => route('owner.messages.reopen', $thread),
            'suggest' => route('owner.messages.assist.suggest', $thread),
            'summarize' => route('owner.messages.assist.summarize', $thread),
            'presence' => route('owner.messages.presence'),
        ],
        'texts' => [
            'you' => 'You',
            'generic' => 'Something went wrong. Please try again.',
            'offline' => 'You seem to be offline. Check your connection and try again.',
            'expired' => config('chat.messages.session_expired'),
            'signedOut' => 'You have been signed out. Please sign in again to continue.',
            'rateLimited' => config('chat.messages.rate_limited'),
            'assistBusy' => 'Lumi needs a short pause before the next suggestion. Please try again in a minute.',
            'assistUnavailable' => config('assistant.messages.owner_assist'),
            'draftReady' => 'A draft is in the reply box. Read and edit it before you send; nothing was sent.',
        ],
    ];
    $tabs = ['all' => 'All', 'unanswered' => 'Unanswered', 'open' => 'Open', 'resolved' => 'Resolved'];
    $name = config('assistant.name');
@endphp

@section('main')
    <div x-data="ownerInbox(@js($config))" class="mx-auto max-w-[1280px]" data-owner-inbox>
        {{-- 9C: on phones an open thread hides the title row below; this keeps one h1 for screen readers there --}}
        @if ($conversation)
            <h1 class="sr-only lg:hidden">Messages</h1>
        @endif
        {{-- Title and presence: one compact row on wide screens, so the conversation gets the height (7F-2) --}}
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between" @if ($conversation) x-bind:class="'max-lg:hidden'" @endif>
            <div class="min-w-0">
                <h1 class="font-display text-2xl font-semibold leading-tight sm:text-3xl">Messages</h1>
                <p class="mt-0.5 text-sm text-app-text-2 lg:truncate">Customer messages from the studio chat. {{ $name }}, the AI assistant, answers only while you are away.</p>
            </div>

            <section aria-labelledby="presence-title" class="flex shrink-0 flex-col gap-1 sm:flex-row sm:items-center sm:gap-3" data-presence>
                <h2 id="presence-title" class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Your availability</h2>
                <div class="flex flex-col gap-1">
                    <div class="grid grid-cols-3 gap-1 rounded-control border border-app-border bg-app-card p-1 dark:bg-white/5" role="radiogroup" aria-labelledby="presence-title">
                        @foreach (['available' => 'Available', 'auto' => 'Auto', 'away' => 'Away'] as $mode => $label)
                            <button type="button" role="radio" x-bind:aria-checked="presence.mode === '{{ $mode }}'" x-on:click="setPresence('{{ $mode }}')" x-bind:disabled="presence.saving"
                                x-bind:class="presence.mode === '{{ $mode }}' ? 'bg-neutral-100 text-app-text dark:bg-white/15' : 'text-app-text-2 hover:bg-black/5 dark:hover:bg-white/5'"
                                class="min-h-11 rounded-control px-3 text-sm font-semibold transition-colors duration-150 focus-ring disabled:opacity-60 sm:min-h-9">{{ $label }}</button>
                        @endforeach
                    </div>
                    <p class="flex items-center gap-1.5 text-xs text-app-text-2" aria-live="polite">
                        <span class="size-2 shrink-0 rounded-full" x-bind:class="presence.away ? 'bg-status-amber' : 'bg-status-green'" aria-hidden="true"></span>
                        <span class="truncate" x-text="presence.reason || 'Checking your availability'"></span>
                    </p>
                </div>
            </section>
        </div>

        {{-- Filters and search --}}
        <div class="mt-4 flex flex-col gap-2 rounded-card border border-app-border bg-app-card p-1.5 shadow-sm md:flex-row md:items-center dark:shadow-none" @if ($conversation) x-bind:class="'max-lg:hidden'" @endif>
            <div class="flex gap-1 relative overflow-x-auto" role="tablist" aria-label="Filter conversations">
                @foreach ($tabs as $key => $label)
                    <button type="button" role="tab" x-bind:aria-selected="list.filter === '{{ $key }}'" x-on:click="setFilter('{{ $key }}')"
                        x-bind:class="list.filter === '{{ $key }}' ? 'bg-brand text-white' : 'text-app-text-2 hover:bg-black/5 dark:hover:bg-white/5'"
                        class="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center gap-1.5 rounded-control px-3 text-sm font-semibold transition-colors duration-150 focus-ring md:min-h-10">
                        {{ $label }}
                        @if ($key === 'unanswered')
                            <span x-show="list.unanswered > 0" x-cloak class="grid h-5 min-w-5 place-items-center rounded-full bg-white/90 px-1 text-[11px] font-bold text-brand" x-text="list.unanswered"></span>
                        @endif
                    </button>
                @endforeach
            </div>
            <label class="flex min-h-11 flex-1 items-center gap-2 rounded-control bg-neutral-100 px-3 focus-within:shadow-[0_0_0_3px_rgba(198,40,40,0.12)] md:min-h-10 dark:bg-white/5">
                <x-ui.icon name="search" :size="18" class="text-app-text-2" />
                <span class="sr-only">Search by customer name or an order or booking reference</span>
                <input type="search" x-model="list.search" x-on:input="onSearch()" maxlength="60" placeholder="Search by customer name or PR-/PB- reference"
                    class="min-h-11 min-w-0 flex-1 bg-transparent py-2 text-base placeholder:text-app-text-2 focus:outline-none sm:text-sm md:min-h-10">
            </label>
        </div>

        {{-- On wide screens the list and the thread share one height that fills the window; each scrolls on its own --}}
        <div class="mt-3 grid grid-cols-1 gap-4 lg:grid-cols-12">
            {{-- The threads --}}
            <section aria-label="Conversations" class="chat-scroll min-w-0 lg:col-span-4 lg:-mr-1 lg:h-[calc(100dvh-16.25rem)] lg:overflow-y-auto lg:pr-1 lg:pt-1" @if ($conversation) x-bind:class="'max-lg:hidden'" @endif data-inbox-list>
                <p x-show="list.loading && !list.loaded" class="flex items-center gap-2 p-4 text-sm text-app-text-2"><x-ui.aperture-spinner :size="20" />Loading conversations</p>

                <div x-show="list.loaded && list.items.length === 0" x-cloak class="rounded-card border border-dashed border-app-border p-8 text-center">
                    <span class="mx-auto grid size-12 place-items-center rounded-card bg-neutral-100 text-app-text-2 dark:bg-white/5" aria-hidden="true"><x-ui.icon name="forum" :size="24" /></span>
                    <p class="mt-3 font-semibold" x-text="list.search.trim() !== '' ? 'No conversation matches your search' : (list.filter === 'all' ? 'No messages yet' : 'Nothing here right now')"></p>
                    <p class="mt-1 text-sm text-app-text-2">When a customer writes in the studio chat, the conversation appears here.</p>
                </div>

                <ul class="space-y-1.5">
                    <template x-for="item in list.items" x-bind:key="item.id">
                        <li>
                            <a x-bind:href="rowUrl(item)" x-bind:aria-current="item.id === conversationId ? 'page' : false"
                                x-bind:class="item.id === conversationId ? 'border-brand ring-1 ring-brand' : 'border-app-border hover:border-neutral-300 dark:hover:border-white/20'"
                                class="block rounded-card border bg-app-card px-3 py-2.5 transition-colors duration-150 focus-ring" data-inbox-row>
                                <span class="flex items-start gap-2.5">
                                    <span class="relative grid size-9 shrink-0 place-items-center rounded-full bg-neutral-100 text-app-text-2 dark:bg-white/10" aria-hidden="true">
                                        <x-ui.icon name="person" :size="18" />
                                        <span x-show="item.unread_count > 0" class="absolute -right-0.5 -top-0.5 size-3 rounded-full bg-brand ring-2 ring-app-card"></span>
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm font-semibold" x-text="item.customer.name"></span>
                                        <span class="mt-0.5 block truncate text-sm text-app-text-2" x-text="(item.last_sender_type === 'owner' ? 'You: ' : (item.last_sender_type === 'assistant' ? '{{ $name }} (AI): ' : '')) + (item.preview ?? '')"></span>
                                        {{-- The time sits with the tags, so the name keeps the full width of the row --}}
                                        <span class="mt-1.5 flex flex-wrap items-center gap-1.5 text-[11px] font-semibold">
                                            <span class="mr-auto font-mono font-normal text-app-text-2" x-text="item.last_message_at?.local ?? ''"></span>
                                            <span x-show="item.unread_count > 0" class="rounded-full bg-brand px-2 py-0.5 text-white" x-text="item.unread_count + ' new'"></span>
                                            <span x-show="item.needs_reply && !item.resolved" class="rounded-full bg-status-amber-bg px-2 py-0.5 text-[#92400E]">Unanswered</span>
                                            <span x-show="item.needs_owner" class="inline-flex items-center gap-1 rounded-full bg-status-violet-bg px-2 py-0.5 text-status-violet"><x-ui.icon name="support_agent" :size="12" />{{ $name }} handed off</span>
                                            <span x-show="item.resolved" class="rounded-full bg-status-gray-bg px-2 py-0.5 text-status-gray">Resolved</span>
                                        </span>
                                    </span>
                                </span>
                            </a>
                        </li>
                    </template>
                </ul>

                <nav x-show="list.lastPage > 1" x-cloak class="mt-3 flex items-center justify-between gap-2 text-sm" aria-label="Conversation pages">
                    <button type="button" x-on:click="goToPage(list.page - 1)" x-bind:disabled="list.page <= 1" class="inline-flex min-h-11 items-center gap-1 rounded-control px-3 font-semibold hover:bg-black/5 disabled:opacity-40 focus-ring dark:hover:bg-white/5"><x-ui.icon name="chevron_left" :size="18" />Newer</button>
                    <span class="text-app-text-2" x-text="'Page ' + list.page + ' of ' + list.lastPage"></span>
                    <button type="button" x-on:click="goToPage(list.page + 1)" x-bind:disabled="list.page >= list.lastPage" class="inline-flex min-h-11 items-center gap-1 rounded-control px-3 font-semibold hover:bg-black/5 disabled:opacity-40 focus-ring dark:hover:bg-white/5">Older<x-ui.icon name="chevron_right" :size="18" /></button>
                </nav>
            </section>

            {{-- The open thread --}}
            <section aria-label="Conversation" class="min-w-0 lg:col-span-8 {{ $conversation ? '' : 'max-lg:hidden' }}" data-inbox-thread>
                @if ($conversation)
                    {{-- Fills the window: on phones and tablets the thread is the whole page, on wide screens it sits beside the list --}}
                    <div class="flex h-[calc(100dvh-6.5rem)] min-h-[26rem] flex-col overflow-hidden rounded-card border border-app-border bg-app-card shadow-sm lg:h-[calc(100dvh-16.25rem)] dark:shadow-none">
                        {{-- One row: who, where to look, and the three actions as quiet buttons (labels from 1280px) --}}
                        <header class="flex items-center gap-2 border-b border-app-border bg-neutral-100/80 px-2 py-2 dark:bg-white/5 sm:px-3">
                            <a href="{{ route('owner.messages.index') }}" class="grid size-11 shrink-0 place-items-center rounded-control hover:bg-black/5 focus-ring lg:hidden dark:hover:bg-white/10" aria-label="Back to all conversations"><x-ui.icon name="arrow_back" /></a>
                            <div class="min-w-0 flex-1 pl-1">
                                <h2 class="flex min-w-0 items-center gap-1.5 font-semibold">
                                    <span class="truncate">{{ $conversation->customer->name }}</span>
                                    <span x-show="thread.conversation?.resolved" x-cloak class="shrink-0 rounded-full bg-status-gray-bg px-2 py-px text-[11px] font-semibold text-status-gray">Resolved</span>
                                </h2>
                                <p class="flex min-w-0 items-center gap-x-3 overflow-hidden whitespace-nowrap text-xs">
                                    <a href="{{ route('owner.customers.show', $conversation->customer_id) }}" class="inline-flex min-h-11 shrink-0 items-center gap-1 font-semibold text-brand hover:underline focus-ring sm:min-h-6 dark:text-[#ffb4ac]"><x-ui.icon name="person" :size="14" />Customer page</a>
                                    <template x-for="about in aboutLinks" x-bind:key="about.url">
                                        <a x-bind:href="about.url" class="inline-flex min-h-11 shrink-0 items-center gap-1 font-mono text-app-text-2 hover:text-brand focus-ring sm:min-h-6"><x-ui.icon name="receipt_long" :size="14" /><span x-text="about.reference"></span></a>
                                    </template>
                                </p>
                            </div>
                            @php($quiet = 'inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center gap-1.5 rounded-control px-2 text-sm font-semibold text-app-text-2 transition-colors duration-150 hover:bg-black/5 hover:text-app-text disabled:opacity-50 focus-ring dark:hover:bg-white/10 xl:px-2.5')
                            <div class="flex shrink-0 items-center">
                                <button type="button" x-on:click="runAssist('suggest')" x-bind:disabled="assist.busy !== null || !thread.loaded" title="Suggest reply: {{ $name }} writes a draft you edit" class="{{ $quiet }}" data-assist-suggest>
                                    <x-ui.icon name="edit_note" :size="20" x-bind:class="assist.busy === 'suggest' && 'animate-pulse motion-reduce:animate-none'" /><span class="sr-only xl:not-sr-only" x-text="assist.busy === 'suggest' ? 'Writing a draft' : 'Suggest reply'">Suggest reply</span>
                                </button>
                                <button type="button" x-on:click="runAssist('summarize')" x-bind:disabled="assist.busy !== null || !thread.loaded" title="Summarize this thread" class="{{ $quiet }}" data-assist-summarize>
                                    <x-ui.icon name="summarize" :size="20" x-bind:class="assist.busy === 'summarize' && 'animate-pulse motion-reduce:animate-none'" /><span class="sr-only xl:not-sr-only" x-text="assist.busy === 'summarize' ? 'Summarizing' : 'Summarize'">Summarize</span>
                                </button>
                                <button type="button" x-show="!thread.conversation?.resolved" x-on:click="setResolved(true)" title="Resolve this conversation" class="{{ $quiet }}"><x-ui.icon name="task_alt" :size="20" /><span class="sr-only xl:not-sr-only">Resolve</span></button>
                                <button type="button" x-show="thread.conversation?.resolved" x-cloak x-on:click="setResolved(false)" title="Reopen this conversation" class="{{ $quiet }}"><x-ui.icon name="undo" :size="20" /><span class="sr-only xl:not-sr-only">Reopen</span></button>
                            </div>
                        </header>

                        {{-- Lumi in this thread: a slim line only while it answers for you; otherwise one quiet word for screen readers and sighted owners alike --}}
                        <p class="flex items-center gap-1.5 border-b px-3 py-1 text-xs sm:px-4" data-lumi-status
                            x-bind:class="thread.state?.studio?.lumi_active ? 'border-status-violet/20 bg-status-violet-bg/70 text-status-violet dark:bg-white/5 dark:text-[#c4b5fd]' : 'border-app-border text-app-text-2'">
                            <x-ui.icon name="camera" :size="14" />
                            <span x-show="thread.state?.studio?.lumi_active">{{ $name }} is answering in this thread while you are away. Your next reply stops it.</span>
                            <span x-show="!thread.state?.studio?.lumi_active">{{ $name }} is not answering in this thread.</span>
                        </p>

                        {{-- Owner assist results: shown only, never sent or stored --}}
                        <div x-show="assist.summary.length > 0 || assist.notice" x-cloak class="chat-scroll mx-2 mt-2 max-h-40 overflow-y-auto rounded-control border border-status-violet/20 bg-status-violet-bg/60 py-2 pl-3 pr-1 text-sm dark:border-white/10 dark:bg-white/5 sm:mx-3" data-assist-panel>
                            <div class="flex items-start gap-2">
                                <div class="min-w-0 flex-1">
                                    <p x-show="assist.summary.length > 0" class="text-[11px] font-bold uppercase tracking-wider text-status-violet dark:text-[#c4b5fd]">Summary by {{ $name }} (AI), check it against the thread</p>
                                    <ul x-show="assist.summary.length > 0" class="mt-1 list-disc space-y-0.5 pl-5">
                                        <template x-for="(line, index) in assist.summary" x-bind:key="index"><li class="[overflow-wrap:anywhere]" x-text="line"></li></template>
                                    </ul>
                                    <p x-show="assist.notice" class="text-app-text-2" x-text="assist.notice"></p>
                                    <p x-show="assist.viaBackup" x-cloak class="mt-1 flex items-center gap-1 text-xs text-app-text-2" data-assist-backup><x-ui.icon name="bolt" :size="14" />Written by the backup assistant because {{ $name }} is busy. It may be shorter; check it before you use it.</p>
                                </div>
                                <button type="button" x-on:click="assist.summary = []; assist.notice = null; assist.viaBackup = false" aria-label="Close" class="-my-2 grid size-11 shrink-0 place-items-center rounded-control focus-ring"><x-ui.icon name="close" :size="18" /></button>
                            </div>
                        </div>

                        {{-- pb-16: the last bubble clears the floating Ask Lumi button when the list is scrolled to its end --}}
                        <div x-ref="log" role="log" aria-live="polite" aria-label="Messages" tabindex="0" class="chat-scroll flex min-h-0 flex-1 flex-col overflow-y-auto px-3 pb-16 pt-4 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand/40 sm:px-4">
                            <button type="button" x-show="thread.state?.has_more_before" x-cloak x-on:click="loadEarlier()" x-bind:disabled="thread.earlier"
                                class="mx-auto inline-flex min-h-11 items-center gap-1.5 rounded-full border border-app-border px-4 text-sm font-semibold text-app-text-2 hover:bg-black/5 disabled:opacity-60 focus-ring dark:hover:bg-white/5">
                                <x-ui.icon name="expand_less" :size="18" />Load earlier
                            </button>
                            <p x-show="thread.loading" class="m-auto flex items-center gap-2 text-sm text-app-text-2"><x-ui.aperture-spinner :size="20" />Loading the conversation</p>
                            <x-chat.rows />
                        </div>

                        <x-chat.banner />

                        <form x-on:submit.prevent="submit()" class="border-t border-app-border px-2 pb-1.5 pt-2 sm:px-3">
                            <x-chat.composer-box :label="'Your reply to '.$conversation->customer->name" send-label="Send reply" placeholder="Write your reply" icon="send" />
                            <div class="mt-0.5 flex min-h-6 items-center justify-between gap-2 px-1 text-[11px] text-app-text-2">
                                <span class="hidden sm:inline">Enter sends, Shift + Enter adds a new line</span>
                                <span x-show="showCounter" x-cloak class="ml-auto font-mono tabular-nums" aria-live="polite" x-text="draft.length + ' / ' + maxChars"></span>
                            </div>
                        </form>
                    </div>
                @else
                    <div class="grid h-full min-h-[24rem] place-items-center rounded-card border border-dashed border-app-border p-8 text-center">
                        <div>
                            <span class="mx-auto grid size-12 place-items-center rounded-card bg-neutral-100 text-app-text-2 dark:bg-white/5" aria-hidden="true"><x-ui.icon name="chat" :size="24" /></span>
                            <p class="mt-3 font-semibold">Choose a conversation</p>
                            <p class="mt-1 text-sm text-app-text-2">Pick a customer on the left to read and answer their messages.</p>
                        </div>
                    </div>
                @endif
            </section>
        </div>
    </div>
@endsection
