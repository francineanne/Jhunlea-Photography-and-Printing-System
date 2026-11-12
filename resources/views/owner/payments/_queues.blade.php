{{--
    The two counter queues of the Payments page (Phase 8B): printing orders ready for pickup (paid ones can be released,
    unpaid ones paid first) and everything awaiting payment, the longest waiting first. Each list is paginated on its
    own (?ready=, ?awaiting=). Recording opens the counter modal on that record; releasing is the usual Completed move
    through StatusTransitionService, so the release gate is unchanged.
--}}
@php
    use App\Enums\PaymentStatus;
    use App\Models\PrintingOrder;
    use App\Services\Counter\CounterService;
    use App\Support\Money;

    $tab = request()->has('awaiting') && ! request()->has('ready') ? 'awaiting' : 'ready';
    $detailUrl = fn ($record) => $record instanceof PrintingOrder ? route('owner.orders.show', $record) : route('owner.bookings.show', $record);
    $payLink = fn ($record) => route('owner.payments.index', ['pay' => $record->getMorphClass().':'.$record->getKey()]);
@endphp

<section class="mt-6 rounded-card border border-app-border bg-app-card p-4 shadow-sm sm:p-6 dark:shadow-none" aria-labelledby="queues-title"
    x-data="{ tab: @js($tab) }">
    <h2 id="queues-title" class="sr-only">Counter queues</h2>
    <div role="tablist" aria-label="Counter queues" class="flex flex-wrap gap-2 border-b border-app-border pb-3">
        <button type="button" role="tab" id="tab-ready" aria-controls="panel-ready" x-bind:aria-selected="tab === 'ready'" x-bind:tabindex="tab === 'ready' ? 0 : -1"
            x-on:click="tab = 'ready'" x-on:keydown.right.prevent="tab = 'awaiting'; $nextTick(() => $refs.awaitingTab.focus())"
            x-bind:class="tab === 'ready' ? 'bg-ink text-white dark:bg-white dark:text-ink' : 'text-app-text-2 hover:bg-black/5 dark:hover:bg-white/10'"
            class="inline-flex min-h-11 items-center gap-2 rounded-full px-4 text-sm font-semibold transition-colors focus-ring">
            <x-ui.icon name="inventory_2" :size="18" />Ready for pickup <span class="rounded-full bg-brand px-2 py-0.5 text-xs text-white tabular-nums">{{ $readyCount }}</span>
        </button>
        <button type="button" role="tab" id="tab-awaiting" aria-controls="panel-awaiting" x-ref="awaitingTab" x-bind:aria-selected="tab === 'awaiting'" x-bind:tabindex="tab === 'awaiting' ? 0 : -1"
            x-on:click="tab = 'awaiting'" x-on:keydown.left.prevent="tab = 'ready'; $nextTick(() => $el.previousElementSibling.focus())"
            x-bind:class="tab === 'awaiting' ? 'bg-ink text-white dark:bg-white dark:text-ink' : 'text-app-text-2 hover:bg-black/5 dark:hover:bg-white/10'"
            class="inline-flex min-h-11 items-center gap-2 rounded-full px-4 text-sm font-semibold transition-colors focus-ring">
            <x-ui.icon name="pending_actions" :size="18" />Awaiting payment <span class="rounded-full bg-status-red px-2 py-0.5 text-xs text-white tabular-nums">{{ $awaitingCount }}</span>
        </button>
    </div>

    {{-- Ready for pickup --}}
    <div role="tabpanel" id="panel-ready" aria-labelledby="tab-ready" x-show="tab === 'ready'" @if ($tab !== 'ready') x-cloak @endif>
        <h3 id="ready-title" class="sr-only">Ready for pickup</h3>
        @if ($ready->isEmpty())
            <p class="mt-4 rounded-control bg-neutral-100 p-3 text-sm text-app-text-2 dark:bg-white/5">No printing order is ready for pickup.</p>
        @else
            <ul class="mt-2 divide-y divide-app-border">
                @foreach ($ready as $order)
                    @php $settled = $order->paymentStatus()->isSettled(); @endphp
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <div class="min-w-0">
                            <p class="flex flex-wrap items-center gap-2">
                                <a href="{{ $detailUrl($order) }}" class="inline-flex min-h-11 items-center rounded font-mono text-sm font-semibold hover:text-brand hover:underline focus-ring sm:min-h-0">{{ $order->reference_number }}</a>
                                <x-walk-in.badge :record="$order" />
                                <x-ui.badge :tone="$settled ? 'green' : 'red'">{{ $order->paymentStatus()->label() }}</x-ui.badge>
                            </p>
                            <p class="mt-0.5 truncate text-sm">{{ $order->customer?->name ?? 'Deleted account' }} <span class="text-app-text-2">· {{ $order->serviceName() }}</span></p>
                        </div>
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="font-semibold tabular-nums">{{ $order->confirmed_price !== null ? Money::format($order->confirmed_price) : 'No price' }}</span>
                            @if ($settled)
                                <form method="POST" action="{{ route('owner.orders.complete', $order) }}">
                                    @csrf
                                    <input type="hidden" name="return" value="payments">
                                    <x-ui.button type="submit" size="sm" variant="outline"><x-ui.icon name="task_alt" :size="18" />Release (completed)</x-ui.button>
                                </form>
                            @else
                                <x-ui.button size="sm" :href="$payLink($order)" x-data x-on:click.prevent="$dispatch('counter-open', { type: 'printing_order', id: {{ $order->id }} })">
                                    <x-ui.icon name="add_card" :size="18" />Record payment
                                </x-ui.button>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
            <div class="mt-4">{{ $ready->links('pagination.simple') }}</div>
        @endif
    </div>

    {{-- Awaiting payment --}}
    <div role="tabpanel" id="panel-awaiting" aria-labelledby="tab-awaiting" x-show="tab === 'awaiting'" @if ($tab !== 'awaiting') x-cloak @endif>
        <h3 id="awaiting-title" class="sr-only">Awaiting payment</h3>
        <p class="mt-3 text-xs text-app-text-2">Confirmed prices with no payment recorded yet, the longest waiting first. Total {{ Money::format(Money::fromCents($awaiting['cents'])) }}.</p>
        @if ($awaitingQueue->isEmpty())
            <p class="mt-4 rounded-control bg-neutral-100 p-3 text-sm text-app-text-2 dark:bg-white/5">Nothing is waiting for payment.</p>
        @else
            <ul class="mt-2 divide-y divide-app-border">
                @foreach ($awaitingQueue as $record)
                    @php $days = CounterService::daysWaiting($record); $isOrder = $record instanceof PrintingOrder; @endphp
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <div class="min-w-0">
                            <p class="flex flex-wrap items-center gap-2">
                                <a href="{{ $detailUrl($record) }}#payment" class="inline-flex min-h-11 items-center rounded font-mono text-sm font-semibold hover:text-brand hover:underline focus-ring sm:min-h-0">{{ $record->reference_number }}</a>
                                <x-walk-in.badge :record="$record" />
                                <span class="text-xs text-app-text-2">{{ $isOrder ? 'Printing order' : 'Photoshoot' }} · {{ $record->status->label() }}</span>
                            </p>
                            <p class="mt-0.5 truncate text-sm">{{ $record->customer?->name ?? 'Deleted account' }}</p>
                            <p class="text-xs text-app-text-2">{{ $days === 0 ? 'Came in today' : 'Waiting '.$days.' '.Str::plural('day', $days) }}</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="font-semibold tabular-nums">{{ Money::format($record->confirmed_price) }}</span>
                            <x-ui.button size="sm" :href="$payLink($record)" x-data x-on:click.prevent="$dispatch('counter-open', { type: '{{ $record->getMorphClass() }}', id: {{ $record->id }} })">
                                <x-ui.icon name="add_card" :size="18" />Record payment
                            </x-ui.button>
                        </div>
                    </li>
                @endforeach
            </ul>
            <div class="mt-4">{{ $awaitingQueue->links('pagination.simple') }}</div>
        @endif
    </div>
</section>
