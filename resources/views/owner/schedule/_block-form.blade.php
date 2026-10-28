{{-- Blocks one day or a range of days. Used on the editor and in the calendar's side panel (the same POST). --}}
<form method="POST" action="{{ route('owner.schedule.block') }}" class="space-y-3">
    @csrf
    <div class="grid gap-3 sm:grid-cols-2 {{ $compact ?? false ? '' : 'lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,2fr)]' }}">
        <x-ui.input id="{{ $idPrefix }}-from" name="from" type="date" label="First day" :required="true" value="{{ old('from') }}" />
        <x-ui.input id="{{ $idPrefix }}-to" name="to" type="date" label="Last day (optional)" value="{{ old('to') }}" hint="Leave empty to block one day." />
        <div class="sm:col-span-2 {{ $compact ?? false ? '' : 'lg:col-span-1' }}">
            <x-ui.input id="{{ $idPrefix }}-reason" name="reason" label="Reason (optional)" maxlength="{{ config('booking.blocked_reason_max') }}" value="{{ old('reason') }}"
                placeholder="For example: holiday, studio maintenance" hint="Up to {{ config('booking.blocked_reason_max') }} characters. Shown on your calendar only." />
        </div>
    </div>
    <p class="text-xs text-app-text-2">Up to {{ config('booking.max_lookup_days') }} days at once, from today on. Customers cannot request times on blocked days.</p>
    @include('owner.schedule._impact', ['section' => 'blocked'])
    <x-ui.button type="submit" variant="secondary"><x-ui.icon name="event_busy" :size="18" />Block {{ $compact ?? false ? 'days' : 'these days' }}</x-ui.button>
</form>
