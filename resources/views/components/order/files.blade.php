@props(['files', 'customerId', 'viewer' => 'customer'])

{{-- The files of an order: names only, never a path. Each downloads through the protected route. --}}
@if ($files->isEmpty())
    <p {{ $attributes->class('text-sm text-app-text-2') }}>No files yet.</p>
@else
    <ul {{ $attributes->class('space-y-2') }}>
        @foreach ($files as $file)
            <li class="flex flex-wrap items-center gap-3 rounded-control bg-neutral-100 p-3 transition-colors hover:bg-neutral-200/70 dark:bg-white/5 dark:hover:bg-white/10">
                <span class="grid size-10 shrink-0 place-items-center rounded-lg bg-app-card text-brand" aria-hidden="true"><x-ui.icon name="description" :size="22" /></span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold" title="{{ $file->original_name }}">{{ $file->original_name }}</p>
                    <p class="text-xs text-app-text-2">
                        {{ $file->sizeText() }} · <x-ui.date :value="$file->created_at" />
                        @if ($viewer === 'owner')
                            · {{ $file->uploaded_by === $customerId ? 'From the customer' : 'Added by the shop' }}
                        @elseif ($file->uploaded_by !== $customerId)
                            · Added by the shop
                        @endif
                    </p>
                </div>
                <a href="{{ route('files.download', $file) }}" aria-label="Download {{ $file->original_name }}"
                    class="inline-flex min-h-11 shrink-0 items-center gap-1.5 rounded-control border border-app-border bg-app-card px-3 text-sm font-semibold transition-colors hover:border-brand hover:text-brand focus-ring sm:min-h-9">
                    <x-ui.icon name="download" :size="18" /><span>Download</span>
                </a>
            </li>
        @endforeach
    </ul>
@endif
