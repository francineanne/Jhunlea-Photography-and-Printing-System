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
        {{-- Title and presence --}}
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between" @if ($conversation) x-bind:class="'max-lg:hidden'" @endif>
            <div>
                <p class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider text-brand dark:text-[#ffb4ac]"><span class="size-1.5 rounded-full bg-brand" aria-hidden="true"></span>Studio chat</p>
                <h1 class="mt-1 font-display text-3xl font-semibold leading-tight sm:text-4xl">Messages</h1>
                <p class="mt-1 text-sm text-app-text-2">Customer messages from the studio chat. {{ $name }}, the AI assistant, answers only while you are away.</p>
            </div>

            <section aria-labelledby="presence-title" class="rounded-card border border-app-border bg-app-card p-3 shadow-sm dark:shadow-none" data-presence>
                <h2 id="presence-title" class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Your availability</h2>
                <div class="mt-2 grid grid-cols-3 gap-1 rounded-control bg-neutral-100 p-1 dark:bg-white/5" role="radiogroup" aria-labelledby="presence-title">
                    @foreach (['available' => 'Available', 'auto' => 'Auto', 'away' => 'Away'] as $mode => $label)
                        <button type="button" role="radio" x-bind:aria-checked="presence.mode === '{{ $mode }}'" x-on:click="setPresence('{{ $mode }}')" x-bind:disabled="presence.saving"
                            x-bind:class="presence.mode === '{{ $mode }}' ? 'bg-app-card shadow-sm dark:bg-white/15' : 'text-app-text-2 hover:bg-black/5 dark:hover:bg-white/5'"
                            class="min-h-11 rounded-control px-3 text-sm font-semibold transition-colors duration-150 focus-ring disabled:opacity-60">{{ $label }}</button>
                    @endforeach
                </div>
                <p class="mt-2 flex items-center gap-1.5 text-xs text-app-text-2" aria-live="polite">
                    <span class="size-2 shrink-0 rounded-full" x-bind:class="presence.away ? 'bg-status-amber' : 'bg-status-green'" aria-hidden="true"></span>
                    <span x-text="presence.reason || 'Checking your availability'"></span>
                </p>
            </section>
        </div>

        {{-- Filters and search --}}
        <div class="mt-6 flex flex-col gap-3 rounded-card border border-app-border bg-app-card p-2 shadow-sm md:flex-row md:items-center dark:shadow-none" @if ($conversation) x-bind:class="'max-lg:hidden'" @endif>
            <div class="flex gap-1 overflow-x-auto" role="tablist" aria-label="Filter conversations">
                @foreach ($tabs as $key => $label)
                    <button type="button" role="tab" x-bind:aria-selected="list.filter === '{{ $key }}'" x-on:click="setFilter('{{ $key }}')"
                        x-bind:class="list.filter === '{{ $key }}' ? 'bg-brand text-white' : 'text-app-text-2 hover:bg-black/5 dark:hover:bg-white/5'"
                        class="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center gap-1.5 rounded-control px-3 text-sm font-semibold transition-colors duration-150 focus-ring">
                        {{ $label }}
                        @if ($key === 'unanswered')
                            <span x-show="list.unanswered > 0" x-cloak class="grid h-5 min-w-5 place-items-center rounded-full bg-white/90 px-1 text-[11px] font-bold text-brand" x-text="list.unanswered"></span>
                        @endif
                    </button>
                @endforeach
            </div>
            <label class="flex min-h-11 flex-1 items-center gap-2 rounded-control bg-neutral-100 px-3 focus-within:shadow-[0_0_0_3px_rgba(198,40,40,0.12)] dark:bg-white/5">
                <x-ui.icon name="search" :size="18" class="text-app-text-2" />
                <span class="sr-only">Search by customer name or an order or booking reference</span>
                <input type="search" x-model="list.search" x-on:input="onSearch()" maxlength="60" placeholder="Search by customer name or PR-/PB- reference"
                    class="min-h-11 min-w-0 flex-1 bg-transparent py-2 text-base placeholder:text-app-text-2 focus:outline-none sm:text-sm">
            </label>
        </div>

        <div class="mt-4 grid gap-4 lg:grid-cols-12">
            {{-- The threads --}}
            <section aria-label="Conversations" class="lg:col-span-5" @if ($conversation) x-bind:class="'max-lg:hidden'" @endif data-inbox-list>
                <p x-show="list.loading && !list.loaded" class="flex items-center gap-2 p-4 text-sm text-app-text-2"><x-ui.aperture-spinner :size="20" />Loading conversations</p>

                <div x-show="list.loaded && list.items.length === 0" x-cloak class="rounded-card border border-dashed border-app-border p-8 text-center">
                    <span class="mx-auto grid size-12 place-items-center rounded-card bg-neutral-100 text-app-text-2 dark:bg-white/5" aria-hidden="true"><x-ui.icon name="forum" :size="24" /></span>
                    <p class="mt-3 font-semibold" x-text="list.search.trim() !== '' ? 'No conversation matches your search' : (list.filter === 'all' ? 'No messages yet' : 'Nothing here right now')"></p>
                    <p class="mt-1 text-sm text-app-text-2">When a customer writes in the studio chat, the conversation appears here.</p>
                </div>

                <ul class="space-y-2">
                    <template x-for="item in list.items" x-bind:key="item.id">
                        <li>
                            <a x-bind:href="rowUrl(item)" x-bind:aria-current="item.id === conversationId ? 'page' : false"
                                x-bind:class="item.id === conversationId ? 'border-brand ring-1 ring-brand' : 'border-app-border hover:border-neutral-300 dark:hover:border-white/20'"
                                class="block rounded-card border bg-app-card p-3 shadow-sm transition-colors duration-150 focus-ring dark:shadow-none" data-inbox-row>
                                <span class="flex items-start gap-3">
                                    <span class="relative grid size-10 shrink-0 place-items-center rounded-full bg-neutral-100 text-app-text-2 dark:bg-white/10" aria-hidden="true">
                                        <x-ui.icon name="person" />
                                        <span x-show="item.unread_count > 0" class="absolute -right-0.5 -top-0.5 size-3 rounded-full bg-brand ring-2 ring-app-card"></span>
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="flex items-baseline justify-between gap-2">
                                            <span class="truncate font-semibold" x-text="item.customer.name"></span>
                                            <span class="shrink-0 font-mono text-[11px] text-app-text-2" x-text="item.last_message_at?.local ?? ''"></span>
                                        </span>
                                        <span class="mt-0.5 line-clamp-2 block break-words text-sm text-app-text-2" x-text="(item.last_sender_type === 'owner' ? 'You: ' : (item.last_sender_type === 'assistant' ? '{{ $name }} (AI): ' : '')) + (item.preview ?? '')"></span>
                                        <span class="mt-2 flex flex-wrap gap-1.5 text-[11px] font-semibold">
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
            <section aria-label="Conversation" class="lg:col-span-7 {{ $conversation ? '' : 'max-lg:hidden' }}" data-inbox-thread>
                @if ($conversation)
                    <div class="flex h-[calc(100dvh-7.5rem)] min-h-[30rem] flex-col overflow-hidden rounded-card border border-app-border bg-app-card shadow-sm lg:sticky lg:top-20 lg:h-[calc(100dvh-21rem)] lg:min-h-[26rem] dark:shadow-none">
                        <header class="space-y-2 border-b border-app-border bg-neutral-100/80 p-3 dark:bg-white/5 sm:px-4">
                            <div class="flex items-start gap-2">
                                <a href="{{ route('owner.messages.index') }}" class="grid size-11 shrink-0 place-items-center rounded-control hover:bg-black/5 focus-ring lg:hidden dark:hover:bg-white/10" aria-label="Back to all conversations"><x-ui.icon name="arrow_back" /></a>
                                <div class="min-w-0 flex-1">
                                    <h2 class="truncate text-lg font-semibold">{{ $conversation->customer->name }}</h2>
                                    <p class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                                        <a href="{{ route('owner.customers.show', $conversation->customer_id) }}" class="inline-flex min-h-11 items-center gap-1 font-semibold text-brand hover:underline focus-ring sm:min-h-8 dark:text-[#ffb4ac]"><x-ui.icon name="person" :size="14" />Customer page</a>
                                        <template x-for="about in aboutLinks" x-bind:key="about.url">
                                            <a x-bind:href="about.url" class="inline-flex min-h-11 items-center gap-1 font-mono text-app-text-2 hover:text-brand focus-ring sm:min-h-8"><x-ui.icon name="receipt_long" :size="14" /><span x-text="about.reference"></span></a>
                                        </template>
                                        <span x-show="thread.conversation?.resolved" class="rounded-full bg-status-gray-bg px-2 py-0.5 font-semibold text-status-gray">Resolved</span>
                                    </p>
                                </div>
                            </div>
                            <div class="flex flex-wrap items-center gap-1.5">
                                <button type="button" x-on:click="runAssist('suggest')" x-bind:disabled="assist.busy !== null || !thread.loaded" class="inline-flex min-h-11 items-center gap-1.5 rounded-control border border-app-border bg-app-card px-3 text-sm font-semibold hover:bg-black/5 disabled:opacity-60 focus-ring dark:hover:bg-white/10" data-assist-suggest>
                                    <x-ui.icon name="edit_note" :size="18" /><span x-text="assist.busy === 'suggest' ? 'Writing a draft' : 'Suggest reply'"></span>
                                </button>
                                <button type="button" x-on:click="runAssist('summarize')" x-bind:disabled="assist.busy !== null || !thread.loaded" class="inline-flex min-h-11 items-center gap-1.5 rounded-control border border-app-border bg-app-card px-3 text-sm font-semibold hover:bg-black/5 disabled:opacity-60 focus-ring dark:hover:bg-white/10" data-assist-summarize>
                                    <x-ui.icon name="summarize" :size="18" /><span x-text="assist.busy === 'summarize' ? 'Summarizing' : 'Summarize'"></span>
                                </button>
                                <button type="button" x-show="!thread.conversation?.resolved" x-on:click="setResolved(true)" class="inline-flex min-h-11 items-center gap-1.5 rounded-control px-3 text-sm font-semibold text-app-text-2 hover:bg-black/5 focus-ring dark:hover:bg-white/10"><x-ui.icon name="task_alt" :size="18" />Resolve</button>
                                <button type="button" x-show="thread.conversation?.resolved" x-cloak x-on:click="setResolved(false)" class="inline-flex min-h-11 items-center gap-1.5 rounded-control px-3 text-sm font-semibold text-app-text-2 hover:bg-black/5 focus-ring dark:hover:bg-white/10"><x-ui.icon name="undo" :size="18" />Reopen</button>
                            </div>
                            <p class="flex items-center gap-1.5 text-xs" data-lumi-status>
                                <x-ui.icon name="camera" :size="14" class="text-brand dark:text-[#ffb4ac]" />
                                <span x-show="thread.state?.studio?.lumi_active" class="text-app-text-2">{{ $name }} is answering in this thread while you are away. Your next reply stops it.</span>
                                <span x-show="!thread.state?.studio?.lumi_active" class="text-app-text-2">{{ $name }} is not answering in this thread.</span>
                            </p>
                        </header>

                        {{-- Owner assist results: shown only, never sent or stored --}}
                        <div x-show="assist.summary.length > 0 || assist.notice" x-cloak class="border-b border-app-border bg-status-violet-bg/60 px-3 py-2 text-sm dark:bg-white/5 sm:px-4" data-assist-panel>
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

                        <div x-ref="log" role="log" aria-live="polite" aria-label="Messages" tabindex="0" class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-3 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand/40 sm:p-4">
                            <button type="button" x-show="thread.state?.has_more_before" x-cloak x-on:click="loadEarlier()" x-bind:disabled="thread.earlier"
                                class="mx-auto inline-flex min-h-11 items-center gap-1.5 rounded-full border border-app-border px-4 text-sm font-semibold text-app-text-2 hover:bg-black/5 disabled:opacity-60 focus-ring dark:hover:bg-white/5">
                                <x-ui.icon name="expand_less" :size="18" />Load earlier
                            </button>
                            <p x-show="thread.loading" class="m-auto flex items-center gap-2 text-sm text-app-text-2"><x-ui.aperture-spinner :size="20" />Loading the conversation</p>
                            <x-chat.rows />
                        </div>

                        <div x-show="banner" x-cloak role="alert" class="flex flex-wrap items-center gap-2 border-t border-status-red/30 bg-status-red-bg px-3 py-2 text-sm text-[#991B1B] sm:px-4">
                            <x-ui.icon name="error" :size="18" />
                            <p class="min-w-0 flex-1 basis-40" x-text="banner?.text"></p>
                            {{-- !!: Alpine calls an expression that evaluates to a function --}}
                            <button type="button" x-show="!!banner?.retry" x-on:click="runRetry()" class="min-h-11 rounded-control px-2 font-semibold underline focus-ring">Try again</button>
                            <button type="button" x-show="banner?.kind === 'session'" x-on:click="window.location.reload()" class="min-h-11 rounded-control px-2 font-semibold underline focus-ring">Reload the page</button>
                            <button type="button" x-on:click="banner = null" aria-label="Dismiss this message" class="grid size-11 place-items-center rounded-control focus-ring"><x-ui.icon name="close" :size="18" /></button>
                        </div>

                        <form x-on:submit.prevent="submit()" class="border-t border-app-border bg-neutral-100/80 p-2 dark:bg-white/5 sm:p-3">
                            <div class="flex items-end gap-1.5 rounded-control border border-app-border bg-app-card p-1 focus-within:border-brand focus-within:shadow-[0_0_0_3px_rgba(198,40,40,0.12)]">
                                <textarea x-ref="input" x-model="draft" x-on:keydown="onKeydown($event)" x-on:input="resize()" rows="2" maxlength="{{ (int) config('chat.message_max_chars') }}"
                                    aria-label="Your reply to {{ $conversation->customer->name }}" placeholder="Write your reply"
                                    class="max-h-48 min-h-11 min-w-0 flex-1 resize-none bg-transparent px-2 py-2.5 text-base leading-6 placeholder:text-app-text-2 focus:outline-none sm:text-sm"></textarea>
                                <button type="submit" x-bind:disabled="!canSend" aria-label="Send reply"
                                    class="grid size-11 shrink-0 place-items-center rounded-control bg-brand text-white transition-[background-color,transform] duration-150 hover:bg-brand-deep active:scale-95 disabled:cursor-not-allowed disabled:opacity-50 focus-ring">
                                    <x-ui.icon name="send" />
                                </button>
                            </div>
                            <div class="mt-1 flex items-center justify-between gap-2 px-1 text-[11px] text-app-text-2">
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
