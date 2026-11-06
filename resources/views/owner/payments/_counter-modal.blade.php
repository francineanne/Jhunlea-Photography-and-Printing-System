{{--
    The counter's record-payment modal (Phase 8B), Alpine `paymentCounter` (resources/js/counter.js). Four steps: find
    the order or booking, check the amount and take the payment, confirm, done. The amount is always the full
    confirmed price; the server (PaymentService) checks everything again and records one payment at most.
--}}
<div x-data="paymentCounter(@js([
        'searchUrl' => route('owner.payments.counter.search'),
        'targetUrl' => route('owner.payments.counter.target'),
        'recordUrl' => route('owner.payments.counter.record'),
        'paymentsUrl' => route('owner.payments.index'),
        'initial' => $pay,
    ]))" x-on:counter-open.window="openFor($event.detail)" x-on:modal-closed.window="if ($event.detail === 'counter' && step === 'done') finish()">
    <x-ui.modal name="counter" title="Record payment" class="sm:max-w-xl">
        <div aria-live="polite" class="sr-only" x-text="step === 'done' ? 'Payment recorded.' : ''"></div>

        <template x-if="error">
            <p role="alert" class="mb-4 flex items-start gap-2 rounded-control border border-status-red/30 bg-status-red-bg p-3 text-sm text-status-red">
                <x-ui.icon name="error" :size="18" class="mt-0.5 shrink-0" /><span x-text="error"></span>
            </p>
        </template>

        {{-- 1. Find --}}
        <div x-show="step === 'find'">
            <label for="counter-search" class="text-sm font-semibold">Find the order or booking</label>
            <div class="relative mt-2">
                <x-ui.icon name="search" :size="20" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-app-text-2" />
                <input id="counter-search" data-counter-search type="search" x-model="query" x-on:input="onInput()" maxlength="60" autocomplete="off"
                    placeholder="Customer name, phone, PR- or PB- number" aria-describedby="counter-search-help"
                    class="h-11 w-full rounded-control border border-app-border bg-app-surface pl-10 pr-3 text-sm focus:border-brand focus:outline-none focus:ring-[3px] focus:ring-brand/15">
            </div>
            <p id="counter-search-help" class="mt-1.5 text-xs text-app-text-2">Walk-in customers are found by name or phone too.</p>

            <p x-show="searching || loading" class="mt-4 flex items-center gap-2 text-sm text-app-text-2"><x-ui.aperture-spinner class="size-4" />Searching…</p>
            <p x-show="searched && ! searching && results.length === 0" class="mt-4 rounded-control bg-neutral-100 p-3 text-sm text-app-text-2 dark:bg-white/5">Nothing found. Check the spelling, or search by the PR- or PB- number.</p>

            <ul x-show="results.length > 0" class="mt-4 divide-y divide-app-border rounded-control border border-app-border" aria-label="Search results">
                <template x-for="result in results" :key="result.type + result.id">
                    <li>
                        <button type="button" x-on:click="choose(result)" class="flex w-full flex-wrap items-center justify-between gap-2 px-3 py-3 text-left hover:bg-black/[0.03] focus-ring dark:hover:bg-white/5">
                            <span class="min-w-0">
                                <span class="flex flex-wrap items-center gap-2">
                                    <span class="font-mono text-sm font-semibold" x-text="result.reference"></span>
                                    <span x-show="result.walk_in" class="rounded-full bg-status-blue-bg px-2 py-0.5 text-[11px] font-semibold text-status-blue">Walk-in</span>
                                    <span x-show="! result.walk_in" class="rounded-full bg-neutral-100 px-2 py-0.5 text-[11px] font-semibold dark:bg-white/10">Online</span>
                                </span>
                                <span class="mt-0.5 block truncate text-sm" x-text="result.customer"></span>
                                <span class="block text-xs text-app-text-2" x-text="result.kind + ' · ' + result.what + ' · ' + result.status"></span>
                            </span>
                            <span class="text-right">
                                <span class="block font-semibold tabular-nums" x-text="result.price_text"></span>
                                <span class="block text-xs" x-bind:class="result.payable ? 'text-status-red' : 'text-app-text-2'" x-text="result.payment"></span>
                            </span>
                        </button>
                    </li>
                </template>
            </ul>
        </div>

        {{-- 2. Take the payment --}}
        <div x-show="step === 'pay' && target" x-cloak>
            <template x-if="target">
                <div>
                    <div class="rounded-control border border-app-border bg-app-surface p-3">
                        <p class="flex flex-wrap items-center gap-2"><span class="font-mono font-semibold" x-text="target.reference"></span>
                            <span x-show="target.walk_in" class="rounded-full bg-status-blue-bg px-2 py-0.5 text-[11px] font-semibold text-status-blue">Walk-in</span>
                            <span class="text-xs text-app-text-2" x-text="target.status"></span></p>
                        <p class="mt-1 font-semibold" x-text="target.customer"></p>
                        <p class="text-xs text-app-text-2" x-text="target.kind + ' · ' + target.what + (target.when ? ' · ' + target.when : '')"></p>
                    </div>

                    <dl class="mt-4 grid grid-cols-3 gap-2 text-center">
                        <div class="rounded-control bg-neutral-100 p-2 dark:bg-white/5"><dt class="text-[10px] font-bold uppercase tracking-wider text-app-text-2">Total</dt><dd class="font-semibold tabular-nums" x-text="target.price_text"></dd></div>
                        <div class="rounded-control bg-neutral-100 p-2 dark:bg-white/5"><dt class="text-[10px] font-bold uppercase tracking-wider text-app-text-2">Paid so far</dt><dd class="font-semibold tabular-nums" x-text="target.paid_text"></dd></div>
                        <div class="rounded-control bg-brand/10 p-2"><dt class="text-[10px] font-bold uppercase tracking-wider text-brand">To collect</dt><dd class="font-bold tabular-nums text-brand" x-text="target.payable ? target.price_text : '—'"></dd></div>
                    </dl>

                    <template x-if="! target.payable">
                        <p role="note" class="mt-4 flex items-start gap-2 rounded-control border border-app-border bg-neutral-100 p-3 text-sm dark:bg-white/5">
                            <x-ui.icon name="block" :size="18" class="mt-0.5 shrink-0 text-app-text-2" /><span><strong>This cannot be paid now.</strong> <span x-text="target.blocker"></span></span>
                        </p>
                    </template>

                    <div x-show="target.payable" class="mt-5 space-y-4">
                        <fieldset>
                            <legend class="text-sm font-semibold">How did the customer pay?</legend>
                            <div class="mt-2 grid grid-cols-2 gap-2">
                                <label class="flex min-h-11 cursor-pointer items-center gap-2 rounded-control border px-3 text-sm font-semibold has-[:checked]:border-brand has-[:checked]:bg-brand/5 has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-brand/20 border-app-border">
                                    <input type="radio" name="counter_method" value="cash" x-model="method" data-counter-focus class="accent-brand"><x-ui.icon name="payments" :size="18" />Cash
                                </label>
                                <label class="flex min-h-11 cursor-pointer items-center gap-2 rounded-control border px-3 text-sm font-semibold has-[:checked]:border-brand has-[:checked]:bg-brand/5 has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-brand/20 border-app-border">
                                    <input type="radio" name="counter_method" value="gcash" x-model="method" class="accent-brand"><x-ui.icon name="qr_code_2" :size="18" />GCash
                                </label>
                            </div>
                        </fieldset>

                        <div x-show="method === 'gcash'">
                            <label for="counter-gcash" class="text-sm font-semibold">GCash reference number</label>
                            <input id="counter-gcash" type="text" x-model="gcash" maxlength="40" autocomplete="off" aria-describedby="counter-gcash-help"
                                x-bind:aria-invalid="gcashProblem ? 'true' : 'false'"
                                class="mt-2 h-11 w-full rounded-control border border-app-border bg-app-surface px-3 font-mono text-sm uppercase focus:border-brand focus:outline-none focus:ring-[3px] focus:ring-brand/15">
                            <p id="counter-gcash-help" class="mt-1.5 text-xs" x-bind:class="gcashProblem ? 'text-status-red' : 'text-app-text-2'" x-text="gcashProblem || 'From the customer’s GCash receipt. Each reference can be used once.'"></p>
                        </div>

                        <div x-show="method === 'cash'">
                            <label for="counter-tendered" class="text-sm font-semibold">Cash received <span class="font-normal text-app-text-2">(optional, for the change)</span></label>
                            <div class="relative mt-2">
                                <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-app-text-2">₱</span>
                                <input id="counter-tendered" type="text" inputmode="decimal" x-model="tendered" maxlength="11" autocomplete="off" aria-describedby="counter-tendered-help"
                                    x-bind:aria-invalid="tenderedProblem ? 'true' : 'false'"
                                    class="h-11 w-full rounded-control border border-app-border bg-app-surface pl-7 pr-3 text-sm tabular-nums focus:border-brand focus:outline-none focus:ring-[3px] focus:ring-brand/15">
                            </div>
                            <p id="counter-tendered-help" class="mt-1.5 text-xs" x-bind:class="tenderedProblem ? 'text-status-red' : 'text-app-text-2'"
                                x-text="tenderedProblem || 'Only works out the change. It is not saved.'"></p>
                            <p x-show="changeText && ! tenderedProblem" class="mt-2 rounded-control bg-status-green-bg p-3 text-center text-status-green">
                                <span class="block text-[10px] font-bold uppercase tracking-wider">Change to give</span>
                                <span class="text-2xl font-bold tabular-nums" x-text="changeText"></span>
                            </p>
                        </div>

                        <label class="flex min-h-11 cursor-pointer items-start gap-3 rounded-control border border-app-border p-3 text-sm">
                            <input type="checkbox" x-model="collected" class="mt-0.5 size-5 shrink-0 accent-brand">
                            <span><strong>I collected the full amount</strong> <span x-text="'(' + target.price_text + ')'"></span> from the customer, face to face.</span>
                        </label>
                    </div>
                </div>
            </template>
        </div>

        {{-- 3. Confirm --}}
        <div x-show="step === 'confirm' && target" x-cloak>
            <template x-if="target">
                <div>
                    <p class="text-sm text-app-text-2">Check the details. A recorded payment marks this as paid.</p>
                    <dl class="mt-4 divide-y divide-app-border rounded-control border border-app-border text-sm">
                        <div class="flex justify-between gap-3 p-3"><dt class="text-app-text-2">For</dt><dd class="text-right"><span class="font-mono font-semibold" x-text="target.reference"></span><span class="block text-xs text-app-text-2" x-text="target.customer + ' · ' + target.what"></span></dd></div>
                        <div class="flex justify-between gap-3 p-3"><dt class="text-app-text-2">Amount</dt><dd class="text-lg font-bold tabular-nums" x-text="target.price_text"></dd></div>
                        <div class="flex justify-between gap-3 p-3"><dt class="text-app-text-2">Method</dt><dd class="text-right font-semibold" x-text="method === 'cash' ? 'Cash' : 'GCash · ' + gcash.trim().toUpperCase()"></dd></div>
                        <div x-show="changeText" class="flex justify-between gap-3 p-3"><dt class="text-app-text-2">Change to give</dt><dd class="font-semibold tabular-nums" x-text="changeText"></dd></div>
                    </dl>
                </div>
            </template>
        </div>

        {{-- 4. Done --}}
        <div x-show="step === 'done' && done" x-cloak class="text-center">
            <template x-if="done">
                <div>
                    <span class="mx-auto grid size-14 place-items-center rounded-full bg-status-green-bg text-status-green"><x-ui.icon name="check_circle" :size="32" /></span>
                    <p class="mt-3 font-display text-xl font-semibold">Payment recorded</p>
                    <p class="mt-1 font-mono text-lg font-bold text-brand" x-text="done.reference"></p>
                    <p class="mt-1 text-sm"><span class="font-semibold tabular-nums" x-text="done.amount"></span> · <span x-text="done.method"></span> · for <span class="font-mono" x-text="done.for"></span></p>
                    <p x-show="done.change" class="mt-3 rounded-control bg-status-green-bg p-3 text-status-green">Change to give: <strong class="tabular-nums" x-text="done.change"></strong></p>
                </div>
            </template>
        </div>

        <x-slot:footer>
            <template x-if="step === 'find'">
                <x-ui.button variant="ghost" x-on:click="$dispatch('close-modal')">Cancel</x-ui.button>
            </template>
            <template x-if="step === 'pay'">
                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <x-ui.button variant="ghost" x-on:click="back()">Back to search</x-ui.button>
                    <x-ui.button class="disabled:cursor-not-allowed disabled:opacity-50" x-show="target && target.payable" x-on:click="review()" x-bind:disabled="! canReview" x-bind:aria-disabled="! canReview">Continue</x-ui.button>
                </div>
            </template>
            <template x-if="step === 'confirm'">
                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <x-ui.button variant="ghost" x-on:click="back()" x-bind:disabled="saving">Back</x-ui.button>
                    <x-ui.button class="disabled:cursor-wait disabled:opacity-70" data-counter-save x-on:click="save()" x-bind:disabled="saving" x-bind:aria-busy="saving">
                        <span x-show="! saving">Record payment</span><span x-show="saving" x-cloak>Saving…</span>
                    </x-ui.button>
                </div>
            </template>
            <template x-if="step === 'done'">
                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <x-ui.button variant="ghost" x-on:click="finish()">Done</x-ui.button>
                    <x-ui.button data-counter-receipt x-bind:href="done && done.receipt_url" href="#" target="_blank" rel="noopener"><x-ui.icon name="print" :size="18" />Print receipt</x-ui.button>
                </div>
            </template>
        </x-slot:footer>
    </x-ui.modal>
</div>
