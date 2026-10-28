@props(['images'])

{{--
    The customer's reference images (the moodboard), as in the booking designs: square tiles that grow a little on
    hover, each shown through the protected image route (inline only when the file is a real JPEG, PNG or WebP image)
    with its own download link. File names are escaped and never part of an address.
--}}
@if ($images->isEmpty())
    <p {{ $attributes->class('rounded-card bg-neutral-100 p-4 text-sm text-app-text-2 dark:bg-white/5') }}>No reference images were added.</p>
@else
    <ul {{ $attributes->class('grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5') }} aria-label="Reference images">
        @foreach ($images as $image)
            <li class="min-w-0">
                <a href="{{ route('booking-images.show', $image) }}" target="_blank" rel="noopener"
                    class="group relative block aspect-square overflow-hidden rounded-card bg-card shadow-sm focus-ring">
                    <img src="{{ route('booking-images.show', $image) }}" alt="Reference image {{ $loop->iteration }}: {{ $image->original_name }}" loading="lazy" decoding="async"
                        class="size-full object-cover transition-transform duration-[280ms] ease-brand group-hover:scale-[1.03]">
                    <span class="absolute left-2 top-2 rounded bg-black/60 px-1.5 py-0.5 font-mono text-[10px] font-bold uppercase text-white" aria-hidden="true">Ref {{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                </a>
                <p class="mt-1.5 truncate text-xs font-semibold" title="{{ $image->original_name }}">{{ $image->original_name }}</p>
                <a href="{{ route('booking-images.download', $image) }}"
                    class="inline-flex min-h-11 items-center gap-1 rounded text-xs font-semibold text-brand hover:underline focus-ring sm:min-h-8">
                    <x-ui.icon name="download" :size="16" />Download<span class="sr-only"> {{ $image->original_name }}</span>
                </a>
            </li>
        @endforeach
    </ul>
@endif
