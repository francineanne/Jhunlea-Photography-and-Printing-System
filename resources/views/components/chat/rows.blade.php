@php
    $liteName = config('assistant.name').' Lite';
    $liteText = 'Our backup assistant answered this because '.config('assistant.name').' is busy. Answers may be shorter.';
@endphp
{{--
    The rows of a conversation (Phase 7D), shared by the customer chat and the owner's thread. The parent Alpine component
    provides: rows (buildRows), tab, viewer ('guest', 'customer' or 'owner'), retryMessage(), discardMessage(),
    switchTab() and contactOwner(). Everything from a message, a name or a card is drawn with x-text, never as HTML.
--}}
<template x-for="row in rows" x-bind:key="row.key">
    <div class="flex flex-col">
        <template x-if="row.type === 'date'">
            <p class="mx-auto rounded-full bg-neutral-100 px-3 py-1 font-mono text-[11px] text-app-text-2 dark:bg-white/10" x-text="row.text"></p>
        </template>

        <template x-if="row.type === 'notice'">
            <div class="mx-auto flex max-w-[90%] flex-col items-center gap-1" data-chat-notice>
                <p class="whitespace-pre-wrap rounded-control bg-neutral-100 px-3 py-2 text-center text-xs text-app-text-2 [overflow-wrap:anywhere] dark:bg-white/5" x-text="row.message.body"></p>
                {{-- Lumi could not answer: the FAQ and the studio are the ways forward --}}
                <div x-show="tab === 'ask' && row.message.system_kind === 'ai_unavailable'" class="flex flex-wrap justify-center gap-1" data-chat-fallback-actions>
                    @if ($faqUrl ?? null)
                        <a href="{{ $faqUrl }}" class="inline-flex min-h-11 items-center gap-1 rounded-control px-3 text-xs font-semibold text-brand hover:underline focus-ring dark:text-[#ffb4ac]"><x-ui.icon name="help" :size="16" />Browse the FAQ</a>
                    @endif
                    <button type="button" x-on:click="switchTab('studio')" class="inline-flex min-h-11 items-center gap-1 rounded-control px-3 text-xs font-semibold text-brand hover:underline focus-ring dark:text-[#ffb4ac]"><x-ui.icon name="storefront" :size="16" />Message the studio</button>
                </div>
            </div>
        </template>

        <template x-if="row.type === 'bubble'">
            <div class="flex max-w-[92%] items-start gap-2" x-bind:class="row.message.mine ? 'flex-row-reverse self-end' : 'self-start'" x-bind:data-chat-sender="row.message.sender_type">
                <span class="material-symbols-outlined grid size-8 shrink-0 select-none place-items-center rounded-full text-[16px] text-white shadow-sm" aria-hidden="true"
                    x-bind:class="row.message.sender_type === 'assistant' ? 'bg-brand' : (row.message.sender_type === 'owner' ? 'bg-neutral-700' : 'bg-ink dark:bg-neutral-600')"
                    x-text="row.message.sender_type === 'assistant' ? 'auto_awesome' : (row.message.sender_type === 'owner' ? 'storefront' : 'person')"></span>

                <div class="flex min-w-0 flex-col gap-1" x-bind:class="row.message.mine ? 'items-end' : 'items-start'">
                    <p class="flex flex-wrap items-center gap-x-1.5 text-xs" x-bind:class="row.message.mine ? 'justify-end' : ''">
                        <span class="font-semibold" x-text="row.message.sender_label"></span>
                    <span x-show="viewer === 'owner' && row.message.sender_type === 'customer'" class="rounded bg-neutral-100 px-1 py-px text-[9px] font-bold uppercase tracking-wider text-app-text-2 dark:bg-white/10">Customer</span>
                        <span x-show="row.message.sender_type === 'assistant'" class="rounded bg-brand/10 px-1 py-px text-[9px] font-bold uppercase tracking-wider text-brand dark:bg-[#ffb4ac]/15 dark:text-[#ffb4ac]">AI</span>
                        {{-- The backup provider wrote this reply (7E-2a); which provider is never shown --}}
                        <span x-show="row.message.sender_type === 'assistant' && row.message.via_backup === true" x-cloak title="{{ $liteText }}" tabindex="0" data-chat-lite
                            class="inline-flex items-center gap-0.5 whitespace-nowrap rounded-full bg-neutral-100 px-1.5 py-px text-[10px] font-semibold text-app-text-2 focus-ring dark:bg-white/10"><span class="material-symbols-outlined select-none text-[12px] leading-none" aria-hidden="true">bolt</span>{{ $liteName }}<span class="sr-only">: {{ $liteText }}</span></span>
                        <span class="font-mono text-[11px] text-app-text-2" x-show="row.time" x-text="row.time"></span>
                    </p>

                    <a x-show="row.message.about" x-bind:href="row.message.about?.url" class="inline-flex min-h-11 items-center gap-1 rounded-full border border-app-border px-2 py-0.5 font-mono text-[11px] text-app-text-2 hover:text-brand focus-ring sm:min-h-8">
                        <x-ui.icon name="receipt_long" :size="14" /><span x-text="'About ' + row.message.about?.reference"></span>
                    </a>

                    <div class="whitespace-pre-wrap rounded-card px-3 py-2 text-sm [overflow-wrap:anywhere]"
                        x-bind:class="row.message.mine ? 'rounded-tr-none bg-brand-deep text-white' : 'rounded-tl-none bg-neutral-100 text-app-text dark:bg-white/10'"
                        x-text="row.message.body"></div>

                    <button type="button" x-show="tab === 'ask' && row.message.sender_type === 'assistant' && row.message.intent === 'handoff'" x-on:click="contactOwner()" data-chat-handoff
                        class="inline-flex min-h-11 items-center gap-1.5 rounded-control bg-ink px-3 text-sm font-semibold text-white hover:bg-neutral-800 focus-ring dark:bg-white dark:text-ink dark:hover:bg-neutral-200">
                        <x-ui.icon name="support_agent" :size="18" />Contact the owner
                    </button>
                    <p x-show="row.message.via_lumi" class="text-[11px] text-app-text-2">Sent from your Ask Lumi chat</p>
                    <p x-show="row.message.status === 'sending'" class="text-[11px] text-app-text-2">Sending</p>
                    <p x-show="row.message.status === 'failed'" class="flex flex-wrap items-center justify-end gap-x-1 text-xs" data-chat-failed>
                        <span class="inline-flex items-center gap-1 font-semibold text-status-red dark:text-[#ffb4ac]"><x-ui.icon name="error" :size="16" />Not sent.</span>
                        <button type="button" x-on:click="retryMessage(row.message)" class="min-h-11 rounded px-2 font-semibold text-status-red underline focus-ring dark:text-[#ffb4ac]">Retry</button>
                        <button type="button" x-on:click="discardMessage(row.message)" class="min-h-11 rounded px-2 font-semibold text-app-text-2 underline focus-ring">Discard</button>
                    </p>

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
