@extends('layouts.customer')

@section('title', 'My bookings | '.config('app.name'))

@section('main')
    <nav aria-label="Breadcrumb" class="mb-4 text-xs font-semibold uppercase tracking-wider text-app-text-2">
        <ol class="flex flex-wrap items-center gap-1.5">
            <li><a href="{{ route('customer.dashboard') }}" class="inline-flex min-h-11 items-center rounded hover:text-brand focus-ring">My dashboard</a></li>
            <li aria-hidden="true">/</li>
            <li aria-current="page" class="text-brand">My bookings</li>
        </ol>
    </nav>

    <header class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div class="max-w-2xl">
            <h1 class="font-display text-3xl font-semibold leading-tight sm:text-4xl">My photoshoot bookings</h1>
            <p class="mt-2 text-app-text-2">Follow your requests, answer a new time the studio offers, and see your confirmed sessions.</p>
        </div>
        <x-ui.button :href="route('customer.bookings.create')" class="shrink-0"><x-ui.icon name="add_a_photo" :size="18" />Book a photoshoot</x-ui.button>
    </header>

    <x-ui.notice variant="payment" class="mt-6">
        <p class="text-app-text-2">Nothing is charged online. You pay at the studio.</p>
    </x-ui.notice>

    @if ($errors->any())
        <x-ui.notice class="mt-6 !border-status-red/30 !bg-status-red-bg">
            @foreach ($errors->all() as $message)<p>{{ $message }}</p>@endforeach
        </x-ui.notice>
    @endif

    {{-- Status tabs with the customer's own counts, as in the design --}}
    <nav aria-label="Filter by status" class="mt-6 -mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
        <ul class="flex w-max gap-2">
            <li>
                <a href="{{ route('customer.bookings.index') }}" @if (! $status) aria-current="page" @endif
                    @class(['inline-flex min-h-11 items-center gap-2 whitespace-nowrap rounded-xl px-4 text-sm font-semibold transition-all duration-200 focus-ring',
                        'bg-brand text-white shadow-sm' => ! $status,
                        'bg-neutral-100 text-app-text-2 hover:bg-neutral-200 hover:text-app-text dark:bg-white/5 dark:hover:bg-white/10' => $status])>
                    All <span @class(['rounded-full px-2 py-0.5 text-xs tabular-nums', 'bg-white/20' => ! $status, 'bg-black/10 dark:bg-white/15' => $status])>{{ $counts->sum() }}</span>
                </a>
            </li>
            @foreach ($statuses as $item)
                <li>
                    <a href="{{ route('customer.bookings.index', ['status' => $item->value]) }}" @if ($status === $item) aria-current="page" @endif
                        @class(['inline-flex min-h-11 items-center gap-2 whitespace-nowrap rounded-xl px-4 text-sm font-semibold transition-all duration-200 focus-ring',
                            'bg-brand text-white shadow-sm' => $status === $item,
                            'bg-neutral-100 text-app-text-2 hover:bg-neutral-200 hover:text-app-text dark:bg-white/5 dark:hover:bg-white/10' => $status !== $item])>
                        {{ $item->label() }}
                        <span @class(['rounded-full px-2 py-0.5 text-xs tabular-nums', 'bg-white/20' => $status === $item, 'bg-black/10 dark:bg-white/15' => $status !== $item])>{{ $counts[$item->value] ?? 0 }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>

    <div class="mt-6">
        @if ($bookings->isEmpty())
            <x-ui.card>
                @if ($status)
                    <x-ui.empty-state icon="event_busy" title="No {{ strtolower($status->label()) }} bookings" description="You have no bookings with this status right now.">
                        <x-ui.button :href="route('customer.bookings.index')" variant="outline">Show all my bookings</x-ui.button>
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state icon="photo_camera" title="No bookings yet" description="Choose a package and a free time, and the studio will confirm your session.">
                        <x-ui.button :href="route('customer.bookings.create')"><x-ui.icon name="add_a_photo" :size="18" />Book a photoshoot</x-ui.button>
                    </x-ui.empty-state>
                @endif
            </x-ui.card>
        @else
            <ul class="space-y-4">
                @foreach ($bookings as $booking)
                    @php
                        $cover = $booking->package?->coverImage;
                        $name = $booking->snapshot['package']['name'] ?? $booking->package?->name;
                        $agreed = in_array($booking->status->value, ['confirmed', 'completed'], true);
                    @endphp
                    <li>
                        <article class="group grid gap-4 rounded-card border border-app-border bg-app-card p-3 shadow-sm transition-[transform,box-shadow,border-color] duration-[280ms] ease-brand hover:-translate-y-[3px] hover:shadow-md motion-reduce:hover:translate-y-0 sm:grid-cols-[10rem_minmax(0,1fr)] sm:p-4 lg:grid-cols-[12rem_minmax(0,1fr)_14rem] dark:shadow-none">
                            <a href="{{ route('customer.bookings.show', $booking) }}" tabindex="-1" aria-hidden="true" class="block aspect-[16/9] overflow-hidden rounded-control bg-card sm:aspect-[4/3]">
                                @if ($cover)
                                    <img src="{{ $cover->url() }}" alt="" width="400" height="300" loading="lazy" decoding="async" class="size-full object-cover transition-transform duration-500 ease-brand group-hover:scale-[1.03]">
                                @else
                                    <x-ui.image-placeholder class="size-full" />
                                @endif
                            </a>
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-mono text-xs font-bold text-brand">{{ $booking->reference_number }}</span>
                                    <x-ui.status-badge :status="$booking->status" live />
                                </div>
                                <h2 class="mt-2 break-words font-display text-xl font-semibold leading-snug">
                                    <a href="{{ route('customer.bookings.show', $booking) }}" class="inline-flex min-h-11 items-center rounded hover:text-brand focus-ring">{{ $name }}</a>
                                </h2>
                                <p class="mt-2 flex items-start gap-1.5 text-sm">
                                    <x-ui.icon name="event" :size="18" class="mt-0.5 shrink-0 text-brand" />
                                    <span>
                                        <span class="block font-semibold">{{ $agreed ? '' : 'Requested: ' }}<x-ui.date :value="$booking->starts_at" format="D, M j, Y" /></span>
                                        <span class="block font-mono text-xs text-app-text-2"><x-ui.date :value="$booking->starts_at" format="g:i A" /> – <x-ui.date :value="$booking->ends_at" format="g:i A" /> · Philippine time</span>
                                    </span>
                                </p>
                                @if ($booking->status->value === 'proposed' && $booking->proposed_starts_at)
                                    <p class="mt-2 inline-flex items-center gap-1.5 rounded-control bg-status-blue-bg px-2.5 py-1.5 text-xs font-semibold text-status-blue">
                                        <x-ui.icon name="schedule" :size="16" />New time offered: <x-ui.date :value="$booking->proposed_starts_at" /> · please answer
                                    </p>
                                @endif
                            </div>
                            <div class="flex flex-col justify-between gap-3 sm:col-span-2 lg:col-span-1 lg:items-end lg:text-right">
                                <div>
                                    <p class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">{{ $booking->confirmed_price !== null ? 'Confirmed price' : 'Estimated price' }}</p>
                                    <p class="font-bold tabular-nums {{ $booking->confirmed_price !== null ? 'text-xl' : 'text-base' }}">{{ $booking->confirmedText() ?? $booking->estimateText() }}</p>
                                    <p class="text-xs text-app-text-2">Paid at the studio</p>
                                </div>
                                <x-ui.button :href="route('customer.bookings.show', $booking)" class="w-full lg:w-auto">
                                    View details<span class="sr-only"> of {{ $booking->reference_number }}</span><x-ui.icon name="arrow_forward" :size="18" />
                                </x-ui.button>
                            </div>
                        </article>
                    </li>
                @endforeach
            </ul>

            <div class="mt-6">{{ $bookings->links('pagination.simple') }}</div>
        @endif
    </div>
@endsection
