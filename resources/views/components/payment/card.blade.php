@props(['payable', 'viewer' => 'customer'])

{{--
    The payment card of a printing order or a photography booking (Phase 5A). The shop takes the full confirmed price
    in one payment, face to face (cash or GCash); the system only records it. Customers read the amount to pay, the
    status and, once paid, the date, method and PAY reference (never a GCash reference or a void reason). The owner
    also records and voids payments here. Needs `payments` and `recordedPayment` loaded.
--}}
@php
    use App\Enums\PaymentMethod;
    use App\Enums\PaymentStatus;
    use App\Models\PrintingOrder;
    use App\Support\Money;

    $isOrder = $payable instanceof PrintingOrder;
    $place = $isOrder ? 'shop' : 'studio';
    $status = $payable->paymentStatus();
    $paid = $payable->recordedPayment;
    $voided = $payable->payments->reject(fn ($payment) => $payment->isRecorded())->values();
    $owner = $viewer === 'owner';
    $routes = $isOrder
        ? ['store' => route('owner.orders.payments.store', $payable), 'void' => fn ($payment) => route('owner.orders.payments.void', [$payable, $payment])]
        : ['store' => route('owner.bookings.payments.store', $payable), 'void' => fn ($payment) => route('owner.bookings.payments.void', [$payable, $payment])];
    $canRecord = $owner && $payable->paymentCanBeRecorded();
    $canVoid = $owner && $paid !== null && ! $payable->paymentIsFinal();
    $amount = Money::format($payable->confirmed_price);
    $openForm = old('_form');
    $formErrors = $owner && in_array($openForm, ['payment', 'void'], true) ? $errors->all() : [];
    $modal = 'record-payment-'.$payable->getKey();
@endphp

<section id="payment" {{ $attributes->class('scroll-mt-24 rounded-card border border-app-border bg-app-card p-4 shadow-md sm:p-6 dark:shadow-none') }} aria-labelledby="payment-title">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 id="payment-title" class="flex items-center gap-2 font-display text-xl font-semibold"><x-ui.icon name="point_of_sale" :size="22" class="text-brand" />Payment</h2>
        @if ($payable->showsPayment())
            <x-ui.badge :tone="$status->tone()" :pulse="$status === PaymentStatus::Unpaid ? 'pulse' : null">{{ $status->label() }}</x-ui.badge>
        @endif
    </div>

    <div class="mt-4 flex items-baseline justify-between gap-3 rounded-card bg-neutral-100 p-4 dark:bg-white/5">
        <p class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">{{ $paid ? 'Amount paid' : ($owner ? 'Amount due at the '.$place : 'Amount to pay at the '.$place) }}</p>
        <p class="text-right font-bold tabular-nums {{ $payable->confirmed_price !== null ? 'text-2xl text-brand' : 'text-sm text-app-text-2' }}">
            {{ $payable->confirmed_price !== null ? $amount : ($owner ? 'No confirmed price yet' : 'Not confirmed yet') }}
        </p>
    </div>

    <div class="mt-3 space-y-2 text-sm">
        @if ($status === PaymentStatus::AwaitingPrice && $payable->showsPayment())
            <p class="text-app-text-2">{{ $owner ? 'Confirm the price first. The payment is always the full confirmed price.' : 'The '.$place.' will confirm the price first.' }}</p>
        @elseif ($status === PaymentStatus::NoCharge)
            <p class="text-app-text-2">Nothing to pay: the confirmed price is ₱0.00.</p>
        @elseif ($status === PaymentStatus::Unpaid && ! $owner && $payable->showsPayment())
            <p class="text-app-text-2">Pay the full amount at the {{ $place }}{{ $isOrder ? ' when you pick up your order' : ' on the day of your photoshoot' }}. {{ $isOrder ? 'Orders' : 'Photos' }} are released once fully paid.</p>
        @elseif (! $payable->showsPayment())
            <p class="text-app-text-2">Nothing to pay: this {{ $isOrder ? 'order' : 'booking' }} is {{ Str::lower($payable->status->label()) }}.</p>
        @endif

        @if (! $owner && $paid === null && $voided->isNotEmpty())
            <p class="flex items-start gap-2 rounded-control bg-status-amber-bg p-3 text-status-amber"><x-ui.icon name="info" :size="18" class="mt-0.5 shrink-0" />The {{ $place }} cancelled this payment record. Please contact the {{ $place }}.</p>
        @endif
    </div>

    @if ($paid)
        <dl class="mt-4 grid gap-x-4 gap-y-3 rounded-card border border-status-green/30 bg-status-green-bg/40 p-4 text-sm sm:grid-cols-2">
            <div class="min-w-0">
                <dt class="text-[10px] font-bold uppercase tracking-wider text-app-text-2">Payment reference</dt>
                <dd class="mt-0.5 font-mono font-semibold">{{ $paid->reference_number }}</dd>
            </div>
            <div class="min-w-0">
                <dt class="text-[10px] font-bold uppercase tracking-wider text-app-text-2">Amount</dt>
                <dd class="mt-0.5 font-semibold tabular-nums">{{ Money::format($paid->amount) }}</dd>
            </div>
            <div class="min-w-0">
                <dt class="text-[10px] font-bold uppercase tracking-wider text-app-text-2">Method</dt>
                <dd class="mt-0.5 flex items-center gap-1.5"><x-ui.icon :name="$paid->method->icon()" :size="16" class="text-app-text-2" />{{ $paid->method->label() }}</dd>
            </div>
            <div class="min-w-0">
                <dt class="text-[10px] font-bold uppercase tracking-wider text-app-text-2">Paid on</dt>
                <dd class="mt-0.5"><x-ui.date :value="$paid->received_at" /></dd>
            </div>
            @if ($owner)
                @if ($paid->gcash_reference)
                    <div class="min-w-0">
                        <dt class="text-[10px] font-bold uppercase tracking-wider text-app-text-2">GCash reference</dt>
                        <dd class="mt-0.5 break-all font-mono">{{ $paid->gcash_reference }}</dd>
                    </div>
                @endif
                <div class="min-w-0">
                    <dt class="text-[10px] font-bold uppercase tracking-wider text-app-text-2">Recorded by</dt>
                    <dd class="mt-0.5 break-words">{{ $paid->receiver?->name ?? 'Deleted account' }}</dd>
                </div>
            @endif
        </dl>
    @endif

    <p class="mt-3 flex items-start gap-2 text-xs text-app-text-2"><x-ui.icon name="storefront" :size="16" class="mt-px shrink-0 text-brand" />
        <span>Payment is made face to face at the {{ $place }} (cash or GCash). Nothing is charged online. {{ $paid ? 'This is a payment record, not an official receipt.' : '' }}</span>
    </p>

    @if ($formErrors)
        <ul class="mt-4 space-y-1 rounded-control border border-status-red/30 bg-status-red-bg p-3 text-sm text-status-red" role="alert">
            @foreach ($formErrors as $message)
                <li class="flex items-start gap-1"><x-ui.icon name="error" :size="16" class="mt-0.5 shrink-0" /><span class="min-w-0 break-words">{{ $message }}</span></li>
            @endforeach
        </ul>
    @endif

    @if ($canRecord)
        {{-- With scripts: a two-step dialog (choose, then confirm). Without: the plain form below --}}
        <x-ui.button x-cloak class="mt-4 w-full" x-on:click="$dispatch('open-modal', '{{ $modal }}')"><x-ui.icon name="add_card" :size="18" />Record payment</x-ui.button>

        <details class="group mt-4 rounded-card border border-app-border open:bg-neutral-50 dark:open:bg-white/5" data-no-js-only @if ($openForm === 'payment') open @endif>
            <summary class="flex min-h-11 cursor-pointer list-none items-center gap-2 rounded-card px-4 py-2 text-sm font-semibold text-status-green focus-ring [&::-webkit-details-marker]:hidden">
                <x-ui.icon name="add_card" :size="18" />Record payment
                <x-ui.icon name="expand_more" :size="18" class="ml-auto transition-transform group-open:rotate-180" />
            </summary>
            <form method="POST" action="{{ $routes['store'] }}" class="space-y-3 px-4 pb-4">
                @csrf
                <input type="hidden" name="_form" value="payment">
                <input type="hidden" name="expected_amount" value="{{ $payable->confirmed_price }}">
                <fieldset>
                    <legend class="text-sm font-semibold">How did the customer pay?</legend>
                    <div class="mt-2 grid grid-cols-2 gap-2">
                        @foreach (PaymentMethod::cases() as $method)
                            <label class="flex min-h-11 cursor-pointer items-center gap-2 rounded-control border border-app-border px-3 text-sm has-checked:border-brand has-checked:bg-brand/5">
                                <input type="radio" name="method" value="{{ $method->value }}" class="accent-brand" @checked((is_string(old('method')) ? old('method') : 'cash') === $method->value)>
                                {{ $method->label() }}
                            </label>
                        @endforeach
                    </div>
                </fieldset>
                <x-ui.input id="nojs-gcash-{{ $payable->getKey() }}" name="gcash_reference" label="GCash reference (GCash only)" maxlength="40" value="{{ is_string(old('gcash_reference')) ? old('gcash_reference') : '' }}" hint="6 to 40 letters, digits or hyphens, from the customer’s GCash receipt." />
                <x-ui.checkbox name="confirm" value="1" required id="nojs-confirm-{{ $payable->getKey() }}">I confirm I received the full amount of {{ $amount }} at the {{ $place }}.</x-ui.checkbox>
                <x-ui.button type="submit">Record payment</x-ui.button>
            </form>
        </details>

        <x-ui.modal :name="$modal" title="Record payment" class="max-w-xl">
            <form method="POST" action="{{ $routes['store'] }}" id="{{ $modal }}-form"
                x-data="{ step: 1, method: @js(is_string(old('method')) ? old('method') : 'cash'), reference: @js(is_string(old('gcash_reference')) ? old('gcash_reference') : ''), get ready() { return this.method === 'cash' || /^[A-Za-z0-9-]{6,40}$/.test(this.reference.trim()) } }">
                @csrf
                <input type="hidden" name="_form" value="payment">
                <input type="hidden" name="expected_amount" value="{{ $payable->confirmed_price }}">

                <p class="flex items-center gap-2 text-[11px] font-bold uppercase tracking-wider text-brand"><span class="size-1.5 animate-pulse rounded-full bg-brand" aria-hidden="true"></span>Step <span x-text="step">1</span> of 2</p>

                <div class="mt-3 grid grid-cols-2 gap-3 rounded-card bg-neutral-100 p-4 text-center dark:bg-white/5">
                    <div class="min-w-0">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-app-text-2">{{ $isOrder ? 'Order' : 'Booking' }}</p>
                        <p class="mt-1 break-words font-mono text-sm font-semibold">{{ $payable->reference_number }}</p>
                        <p class="break-words text-xs text-app-text-2">{{ $payable->customer?->name ?? 'Deleted account' }}</p>
                    </div>
                    <div class="min-w-0">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-brand">Amount due</p>
                        <p class="mt-1 text-xl font-bold tabular-nums text-brand">{{ $amount }}</p>
                        <p class="text-xs text-app-text-2">The full confirmed price</p>
                    </div>
                </div>

                <div x-show="step === 1" class="mt-5 space-y-4">
                    <fieldset>
                        <legend class="text-sm font-semibold">How did the customer pay?</legend>
                        <div class="mt-2 grid grid-cols-2 gap-3">
                            @foreach (PaymentMethod::cases() as $method)
                                <label class="cursor-pointer">
                                    <input type="radio" name="method" value="{{ $method->value }}" x-model="method" class="peer sr-only">
                                    <span class="flex h-16 flex-col items-center justify-center gap-1 rounded-card bg-neutral-100 text-sm font-semibold transition-all duration-200 ease-brand peer-checked:bg-brand peer-checked:text-white peer-checked:shadow-md peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-brand dark:bg-white/10">
                                        <x-ui.icon :name="$method->icon()" :size="20" />{{ $method->label() }}
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                    <div x-show="method === 'gcash'" x-transition.opacity.duration.200ms>
                        <x-ui.input id="{{ $modal }}-reference" name="gcash_reference" label="GCash reference number" maxlength="40" x-model="reference" autocomplete="off"
                            class="font-mono" hint="6 to 40 letters, digits or hyphens, from the customer’s GCash receipt." />
                    </div>
                    <p class="text-xs text-app-text-2">There is no amount to type: the payment is always the full confirmed price. Record it only after you received it in person.</p>
                </div>

                <div x-show="step === 2" x-cloak class="mt-5 space-y-3">
                    <p class="rounded-card border border-brand/20 bg-brand/5 p-4 text-base">
                        Confirm you received <strong class="tabular-nums">{{ $amount }}</strong>
                        <span x-show="method === 'cash'">by cash.</span>
                        <span x-show="method === 'gcash'">by GCash, reference <strong class="break-all font-mono" x-text="reference.trim().toUpperCase()"></strong>.</span>
                    </p>
                    <x-ui.checkbox name="confirm" value="1" required id="{{ $modal }}-confirm">I received this payment in person at the {{ $place }}.</x-ui.checkbox>
                </div>

                <div class="mt-6 flex flex-col-reverse gap-2 border-t border-app-border pt-4 sm:flex-row sm:justify-end">
                    <x-ui.button variant="outline" x-show="step === 1" x-on:click="$dispatch('close-modal')">Cancel</x-ui.button>
                    <x-ui.button variant="outline" x-show="step === 2" x-cloak x-on:click="step = 1">Back</x-ui.button>
                    <x-ui.button x-show="step === 1" x-on:click="ready ? step = 2 : document.getElementById('{{ $modal }}-reference')?.focus()" x-bind:aria-disabled="! ready">Continue<x-ui.icon name="arrow_forward" :size="18" /></x-ui.button>
                    <x-ui.button type="submit" x-show="step === 2" x-cloak><x-ui.icon name="check" :size="18" />Record payment</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif

    @if ($canVoid)
        <details class="group mt-4 rounded-card border border-app-border open:bg-neutral-50 dark:open:bg-white/5" @if ($openForm === 'void') open @endif>
            <summary class="flex min-h-11 cursor-pointer list-none items-center gap-2 rounded-card px-4 py-2 text-sm font-semibold text-status-red focus-ring [&::-webkit-details-marker]:hidden">
                <x-ui.icon name="undo" :size="18" />Void this payment record
                <x-ui.icon name="expand_more" :size="18" class="ml-auto transition-transform group-open:rotate-180" />
            </summary>
            <form method="POST" action="{{ $routes['void']($paid) }}" class="space-y-3 px-4 pb-4">
                @csrf
                <input type="hidden" name="_form" value="void">
                <x-ui.textarea id="void-reason-{{ $paid->id }}" name="reason" label="Why is it cancelled?" maxlength="500" rows="2" :required="true"
                    hint="Kept with the payment for the shop only. The customer reads only that the record was cancelled.">{{ $openForm === 'void' ? old('reason') : '' }}</x-ui.textarea>
                <x-ui.button type="submit" variant="danger">Void {{ $paid->reference_number }}</x-ui.button>
            </form>
        </details>
    @elseif ($owner && $paid && $payable->paymentIsFinal())
        <p class="mt-4 flex items-start gap-2 text-xs text-app-text-2"><x-ui.icon name="lock" :size="16" class="mt-px shrink-0" />Released, so this payment is final.</p>
    @endif

    @if ($owner && $voided->isNotEmpty())
        <div class="mt-5 border-t border-app-border pt-4">
            <h3 class="text-[11px] font-bold uppercase tracking-wider text-app-text-2">Voided payment records</h3>
            <ul class="mt-2 space-y-2">
                @foreach ($voided as $payment)
                    <li class="rounded-control bg-neutral-100 p-3 text-xs dark:bg-white/5">
                        <p class="flex flex-wrap items-center justify-between gap-2">
                            <span class="font-mono font-semibold line-through decoration-app-text-2">{{ $payment->reference_number }} · {{ Money::format($payment->amount) }} · {{ $payment->methodText() }}</span>
                            <x-ui.badge tone="gray">Voided</x-ui.badge>
                        </p>
                        <p class="mt-1 text-app-text-2">Voided <x-ui.date :value="$payment->voided_at" /> by {{ $payment->voider?->name ?? 'Deleted account' }}</p>
                        <p class="mt-1 whitespace-pre-line break-words">{{ $payment->void_reason }}</p>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</section>
