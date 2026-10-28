@extends('layouts.customer')

@section('title', 'My dashboard')

@section('main')
    <h1 class="font-display text-3xl font-semibold sm:text-4xl">Welcome, {{ auth()->user()->name }}</h1>
    <p class="mt-2 text-app-text-2">This is your account home.</p>

    @if ($needsInfoCount > 0)
        <x-ui.notice class="mt-6 !border-status-red/30 !bg-status-red-bg">
            <p class="font-semibold">The shop needs more information on {{ $needsInfoCount }} {{ Str::plural('order', $needsInfoCount) }}.</p>
            <p><a href="{{ route('customer.printing.index') }}" class="font-semibold text-brand underline-offset-2 hover:underline focus-ring rounded">Open my printing orders</a> to see what the shop asked.</p>
        </x-ui.notice>
    @endif

    <div class="mt-8 grid gap-4 sm:grid-cols-2">
        <a href="{{ route('customer.printing.create') }}" class="group flex items-start gap-4 rounded-card border border-app-border bg-app-card p-5 shadow-sm transition-[border-color,box-shadow] duration-300 hover:border-brand hover:shadow-md focus-ring dark:shadow-none">
            <span class="grid size-11 shrink-0 place-items-center rounded-control bg-brand/10 text-brand" aria-hidden="true"><x-ui.icon name="print" :size="24" /></span>
            <span class="min-w-0">
                <span class="block font-semibold group-hover:text-brand">Request printing</span>
                <span class="mt-0.5 block text-sm text-app-text-2">Choose a service, add your files and send a request.</span>
            </span>
        </a>
        <a href="{{ route('customer.bookings.create') }}" class="group flex items-start gap-4 rounded-card border border-app-border bg-app-card p-5 shadow-sm transition-[border-color,box-shadow] duration-300 hover:border-brand hover:shadow-md focus-ring dark:shadow-none">
            <span class="grid size-11 shrink-0 place-items-center rounded-control bg-brand/10 text-brand" aria-hidden="true"><x-ui.icon name="photo_camera" :size="24" /></span>
            <span class="min-w-0">
                <span class="block font-semibold group-hover:text-brand">Book a photoshoot</span>
                <span class="mt-0.5 block text-sm text-app-text-2">Choose a package and a free time, then send a request to the studio.</span>
            </span>
        </a>
        <a href="{{ route('customer.printing.index') }}" class="group flex items-start gap-4 rounded-card border border-app-border bg-app-card p-5 shadow-sm transition-[border-color,box-shadow] duration-300 hover:border-brand hover:shadow-md focus-ring dark:shadow-none">
            <span class="grid size-11 shrink-0 place-items-center rounded-control bg-brand/10 text-brand" aria-hidden="true"><x-ui.icon name="receipt_long" :size="24" /></span>
            <span class="min-w-0">
                <span class="block font-semibold group-hover:text-brand">My printing orders</span>
                <span class="mt-0.5 block text-sm text-app-text-2">
                    @if ($orderCount === 0)
                        You have not sent a printing request yet.
                    @else
                        {{ $orderCount }} {{ Str::plural('order', $orderCount) }}. See their status, price and files.
                    @endif
                </span>
            </span>
        </a>
    </div>
@endsection
