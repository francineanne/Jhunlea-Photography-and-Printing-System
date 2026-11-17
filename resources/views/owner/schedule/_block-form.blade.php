{{-- Blocks one day or a range of days. Used on the editor and in the calendar's side panel (the same POST). --}}
<form method="POST" action="{{ route('owner.schedule.block') }}" data-confirm="Block these days?" data-confirm-text="Customers cannot request times on blocked days. Bookings already made are never cancelled or moved." data-confirm-ok="Block days" class="space-y-3">
    @csrf
    <div class="form-grid {{ ($compact ?? false) ? '' : 'sm:grid-cols-2' }}">
        <x-ui.input id="{{ $idPrefix }}-from" name="from" type="date" label="First day" :required="true" value="{{ old('from') }}" />
        <x-ui.input id="{{ $idPrefix }}-to" name="to" type="date" label="Last day" value="{{ old('to') }}" hint="Optional. Empty blocks one day." />
        <div class="sm:col-span-2">
            <x-ui.input id="{{ $idPrefix }}-reason" name="reason" label="Reason" maxlength="{{ config('booking.blocked_reason_max') }}" value="{{ old('reason') }}"
                placeholder="For example: holiday, studio maintenance" hint="Optional, up to {{ config('booking.blocked_reason_max') }} characters. Shown on your calendar only." />
        </div>
    </div>
    <p class="text-xs text-app-text-2">Up to {{ config('booking.max_lookup_days') }} days at once, from today on. Customers cannot request times on blocked days.</p>
    @include('owner.schedule._impact', ['section' => 'blocked'])
    <x-ui.button type="submit" variant="secondary"><x-ui.icon name="event_busy" :size="18" />Block {{ $compact ?? false ? 'days' : 'these days' }}</x-ui.button>
</form>
