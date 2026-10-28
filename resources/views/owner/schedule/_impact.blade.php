{{--
    The safety net of a schedule change, inside that section's form: the confirmed sessions the change would leave
    outside the rules (with links), and pending requests as information. When saving needs the owner's go-ahead, the
    "Save anyway" tick is part of this form. Nothing is ever cancelled or moved by a schedule change.
--}}
@php $impact = session('impact'); @endphp
@if (is_array($impact) && ($impact['section'] ?? null) === $section)
    <div @class(['mt-4 rounded-card border p-4 text-sm', 'border-status-amber/40 bg-status-amber-bg' => $impact['needsTick'], 'border-app-border bg-neutral-100 dark:bg-white/5' => ! $impact['needsTick']]) role="alert">
        @if ($impact['confirmed'] !== [])
            <p class="flex items-center gap-1.5 font-semibold"><x-ui.icon name="warning" :size="18" class="text-status-amber" />
                {{ $impact['needsTick'] ? 'These confirmed sessions would fall outside the new schedule:' : 'These confirmed sessions are now outside the schedule. They stay booked; talk to the customers:' }}
            </p>
            <ul class="mt-2 space-y-1">
                @foreach ($impact['confirmed'] as $row)
                    <li class="flex flex-wrap items-baseline gap-x-2">
                        <a href="{{ route('owner.bookings.show', $row['id']) }}" class="inline-flex min-h-9 items-center rounded font-mono font-semibold text-brand hover:underline focus-ring">{{ $row['reference'] }}</a>
                        <span class="break-words">{{ $row['customer'] }} · <x-ui.date :value="$row['starts_at']" /></span>
                    </li>
                @endforeach
            </ul>
        @endif
        @if ($impact['pending'] !== [])
            <p class="mt-3 font-semibold">Requests waiting for you at those times (for your information; they are not changed):</p>
            <ul class="mt-1 space-y-1">
                @foreach ($impact['pending'] as $row)
                    <li class="flex flex-wrap items-baseline gap-x-2">
                        <a href="{{ route('owner.bookings.show', $row['id']) }}" class="inline-flex min-h-9 items-center rounded font-mono font-semibold text-brand hover:underline focus-ring">{{ $row['reference'] }}</a>
                        <span class="break-words">{{ $row['customer'] }} · {{ $row['status'] }} · <x-ui.date :value="$row['starts_at']" /></span>
                    </li>
                @endforeach
            </ul>
        @endif
        @if ($impact['needsTick'])
            <div class="mt-3 border-t border-status-amber/30 pt-2">
                <x-ui.checkbox id="acknowledge-{{ $section }}" name="acknowledge" value="1">Save anyway. These bookings stay as they are; I will talk to the customers.</x-ui.checkbox>
            </div>
        @endif
    </div>
@endif
