{{-- Numbered heading of a wizard step, as in the design. The heading takes focus when Alpine opens the step. --}}
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div class="flex min-w-0 items-start gap-3">
        <span class="grid size-7 shrink-0 place-items-center rounded-lg bg-brand text-sm font-bold text-white" aria-hidden="true">{{ $number }}</span>
        <div class="min-w-0">
            <h2 id="step-{{ $number }}-title" tabindex="-1" class="font-display text-xl font-semibold leading-snug focus:outline-none sm:text-2xl">
                <span class="sr-only">Step {{ $number }} of {{ $total ?? 4 }}: </span>{{ $title }}
            </h2>
            <p class="mt-0.5 text-sm text-app-text-2">{{ $text }}</p>
        </div>
    </div>
    <span class="rounded-lg bg-neutral-100 px-2.5 py-1 font-mono text-xs uppercase text-app-text-2 dark:bg-white/10">{{ $pill }}</span>
</div>
