@props(['payable', 'openForm' => null])

{{--
    Phase 10B: inside the shop's cancel form of an order or booking that has recorded payments. The owner must say what
    happens to the money: keep it (not refunded; it stays income) or refund it (every payment is voided as a refund, with
    the reason, in the same transaction). The services refuse a cancel without the choice.
--}}
@php
    use App\Support\Money;

    $paid = $payable->recordedPayments();
    $choice = $openForm === 'cancel' ? old('payment_choice') : null;
@endphp

@if ($paid->isNotEmpty())
    <fieldset class="space-y-2 rounded-control border border-status-amber/40 bg-status-amber-bg/50 p-3" x-data="{ choice: @js($choice) }">
        <legend class="px-1 text-sm font-semibold">{{ Money::cents($payable->paidCents()) }} is recorded. What happens to the money?</legend>
        <label class="flex min-h-11 cursor-pointer items-start gap-2 text-sm">
            <input type="radio" name="payment_choice" value="keep" x-model="choice" class="mt-1 accent-brand" required @checked($choice === 'keep')>
            <span><span class="font-semibold">Keep the payment (not refunded)</span><span class="block text-xs text-app-text-2">It stays recorded and counted.</span></span>
        </label>
        <label class="flex min-h-11 cursor-pointer items-start gap-2 text-sm">
            <input type="radio" name="payment_choice" value="refund" x-model="choice" class="mt-1 accent-brand" @checked($choice === 'refund')>
            <span><span class="font-semibold">Refund: void the payment{{ $paid->count() > 1 ? 's' : '' }}</span><span class="block text-xs text-app-text-2">Give the money back first; the voided record is kept with the reason.</span></span>
        </label>
        <div x-show="choice === 'refund'" @if ($choice !== 'refund') x-cloak @endif>
            <x-ui.input id="refund-reason-{{ $payable->getMorphClass() }}-{{ $payable->getKey() }}" name="refund_reason" label="Reason for the refund" maxlength="500" :value="$openForm === 'cancel' ? old('refund_reason') : ''" hint="Needed for a refund. The customer does not see it." />
        </div>
    </fieldset>
@endif
