@extends('layouts.owner')

@section('title', 'FAQ editor')

{{--
    The owner's FAQ editor (Phase 6B), styled after the FAQ list of design/admin/settings_studio_configuration_rules:
    numbered cards with the question, a short answer and their actions. Moving is one place up or down with plain POST
    buttons (no drag and drop), so it works without JavaScript; Delete opens a confirm dialog, or the confirm page
    when JavaScript is off.
--}}
@section('main')
    <nav aria-label="Breadcrumb" class="mb-4 text-xs font-semibold uppercase tracking-wider text-app-text-2">
        <ol class="flex flex-wrap items-center gap-1.5">
            <li><a href="{{ route('owner.settings.index') }}#faq" class="inline-flex min-h-11 items-center rounded hover:text-brand focus-ring">Settings</a></li>
            <li aria-hidden="true">/</li>
            <li aria-current="page" class="text-brand">FAQ</li>
        </ol>
    </nav>

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="max-w-2xl">
            <p class="flex items-center gap-2 font-mono text-[13px] font-medium uppercase text-brand"><span class="size-1.5 animate-pulse rounded-full bg-brand" aria-hidden="true"></span>FAQ editor</p>
            <h1 class="mt-2 font-display text-3xl font-semibold tracking-tight sm:text-4xl">Frequently asked questions</h1>
            <p class="mt-2 text-app-text-2">Shown questions appear on the public FAQ page in this order. Hidden ones stay here only.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <x-ui.button :href="route('faq')" variant="outline" target="_blank" rel="noopener"><x-ui.icon name="open_in_new" :size="18" />View FAQ page</x-ui.button>
            @unless ($full)
                <x-ui.button :href="route('owner.faqs.create')"><x-ui.icon name="add" :size="18" />Add a question</x-ui.button>
            @endunless
        </div>
    </div>

    @if ($errors->any())
        <x-ui.notice class="mt-6 !border-status-red/30 !bg-status-red-bg" role="alert">
            @foreach ($errors->all() as $message)<p>{{ $message }}</p>@endforeach
        </x-ui.notice>
    @endif

    <p class="mt-6 font-mono text-xs text-app-text-2">{{ $faqs->count() }} of {{ \App\Models\Faq::MAX_ITEMS }} questions · {{ $faqs->where('is_published', true)->count() }} shown</p>

    @if ($faqs->isEmpty())
        <x-ui.card class="mt-3">
            <x-ui.empty-state icon="quiz" title="No questions yet" description="Add the questions customers ask most. Only the ones you show appear on the public FAQ page.">
                <x-ui.button :href="route('owner.faqs.create')"><x-ui.icon name="add" :size="18" />Add a question</x-ui.button>
            </x-ui.empty-state>
        </x-ui.card>
    @else
        <div x-data="{ target: { question: '', action: '' } }">
        <ol class="mt-3 space-y-3">
            @foreach ($faqs as $faq)
                @php($position = $loop->iteration)
                <li class="rounded-card border border-app-border bg-app-card p-4 shadow-sm transition-shadow hover:shadow-md sm:p-5 dark:shadow-none">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="grid size-7 shrink-0 place-items-center rounded-full bg-neutral-100 font-mono text-xs font-bold dark:bg-white/10">{{ $position }}</span>
                            <div class="min-w-0">
                                <p class="flex flex-wrap items-center gap-2">
                                    <span class="min-w-0 max-w-full break-words font-semibold">{{ $faq->question }}</span>
                                    <x-ui.badge :tone="$faq->is_published ? 'green' : 'gray'">{{ $faq->is_published ? 'Shown' : 'Hidden' }}</x-ui.badge>
                                </p>
                                <p class="mt-1 line-clamp-2 whitespace-pre-line break-words text-sm text-app-text-2">{{ $faq->answer }}</p>
                            </div>
                        </div>
                        <div class="flex shrink-0 flex-wrap items-center gap-1">
                            <form method="POST" action="{{ route('owner.faqs.move', $faq) }}">
                                @csrf
                                <input type="hidden" name="direction" value="up">
                                <input type="hidden" name="from" value="{{ $position }}">
                                <button type="submit" @disabled($loop->first) aria-label="Move “{{ Str::limit($faq->question, 40) }}” up"
                                    class="grid size-11 place-items-center rounded-control text-app-text-2 transition-colors hover:bg-neutral-100 hover:text-app-text focus-ring disabled:cursor-not-allowed disabled:opacity-30 dark:hover:bg-white/10"><x-ui.icon name="arrow_upward" :size="20" /></button>
                            </form>
                            <form method="POST" action="{{ route('owner.faqs.move', $faq) }}">
                                @csrf
                                <input type="hidden" name="direction" value="down">
                                <input type="hidden" name="from" value="{{ $position }}">
                                <button type="submit" @disabled($loop->last) aria-label="Move “{{ Str::limit($faq->question, 40) }}” down"
                                    class="grid size-11 place-items-center rounded-control text-app-text-2 transition-colors hover:bg-neutral-100 hover:text-app-text focus-ring disabled:cursor-not-allowed disabled:opacity-30 dark:hover:bg-white/10"><x-ui.icon name="arrow_downward" :size="20" /></button>
                            </form>
                            <form method="POST" action="{{ route('owner.faqs.publish', $faq) }}">
                                @csrf
                                <input type="hidden" name="published" value="{{ $faq->is_published ? '0' : '1' }}">
                                <x-ui.button type="submit" variant="ghost" size="sm" class="min-h-11"><x-ui.icon :name="$faq->is_published ? 'visibility_off' : 'visibility'" :size="18" />{{ $faq->is_published ? 'Hide' : 'Show' }}</x-ui.button>
                            </form>
                            <x-ui.button :href="route('owner.faqs.edit', $faq)" variant="ghost" size="sm" class="min-h-11"><x-ui.icon name="edit" :size="18" />Edit</x-ui.button>
                            <a href="{{ route('owner.faqs.confirm-delete', $faq) }}"
                                x-on:click.prevent="target = { question: {{ \Illuminate\Support\Js::from(Str::limit($faq->question, 120)) }}, action: {{ \Illuminate\Support\Js::from(route('owner.faqs.destroy', $faq)) }} }; $dispatch('open-modal', 'delete-faq')"
                                class="grid size-11 place-items-center rounded-control text-app-text-2 transition-colors hover:bg-status-red-bg hover:text-status-red focus-ring" aria-label="Delete “{{ Str::limit($faq->question, 40) }}”"><x-ui.icon name="delete" :size="20" /></a>
                        </div>
                    </div>
                </li>
            @endforeach
        </ol>

        <x-ui.modal name="delete-faq" title="Delete this question?">
            <p class="break-words text-app-text-2">“<strong class="text-app-text" x-text="target.question"></strong>” will be removed for good. The other questions keep their order.</p>
            <form id="delete-faq-form" method="POST" x-bind:action="target.action">
                @csrf
                @method('DELETE')
                <input type="hidden" name="confirm" value="1">
            </form>
            <x-slot:footer>
                <x-ui.button variant="outline" x-on:click="$dispatch('close-modal')">Cancel</x-ui.button>
                <x-ui.button type="submit" form="delete-faq-form" variant="danger">Delete question</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
        </div>
    @endif
@endsection
