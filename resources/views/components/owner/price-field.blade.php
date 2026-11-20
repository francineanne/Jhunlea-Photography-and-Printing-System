@props([
    'id',
    'name' => 'confirmed_price',
    'label' => 'Confirmed price (₱)',
    // The price it starts from: the package's price, the printing estimate, or the current confirmed price (null = unknown)
    'base' => null,
    'basis' => 'package',
    // What it shows (an old value after a refused send); defaults to the base
    'value' => null,
    'required' => true,
    // The note when there is no base (for example a package priced on request)
    'empty' => null,
    // true: the parent Alpine scope already has `price`, `basePrice` and `priceNote` (the walk-in booking form)
    'scoped' => false,
    'error' => null,
])

{{--
    Phase 10L: the ONE owner price field. It starts FILLED with the price it is based on, so the owner only types when
    the price really differs; "Changed from ₱1,200.00" shows as soon as it does (no reload). Never the browser's list
    of old values (autocomplete off). The server rules are unchanged: what is posted is validated as before.
--}}
@php
    $error ??= isset($errors) ? $errors->first($name) : null;
    $baseText = $base !== null && $base !== '' ? \App\Support\Money::format($base) : null;
    [$lead, $tail] = match ($basis) {
        'estimate' => ['Estimated price', 'Change it only if the final price is different.'],
        'current' => ['Current price', 'Type the new price.'],
        default => ['Package price', 'Change it only if this session costs differently.'],
    };
    $note = $baseText !== null ? "{$lead}: {$baseText}. {$tail}" : $empty;
    $value = (string) ($value ?? $base ?? '');
@endphp

<div {{ $attributes->class(['min-w-0']) }} @unless ($scoped) x-data="ownerPrice(@js($base === '' ? null : $base), @js($value))" @endunless data-price-field>
    <x-ui.field :id="$id" :label="$label" :error="$error" :required="$required">
        <div>
            <input id="{{ $id }}" name="{{ $name }}" type="text" inputmode="decimal" autocomplete="off" value="{{ $value }}" x-model="price"
                @required($required) @if ($error) aria-invalid="true" @endif
                aria-describedby="{{ $id }}-note{{ $error ? ' '.$id.'-error' : '' }}"
                @class(['block h-11 w-full rounded-control border bg-app-card px-3.5 text-base tabular-nums text-app-text transition-[border-color,box-shadow] duration-150 sm:text-sm',
                    'placeholder:text-app-muted field-focus',
                    'border-neutral-200 dark:border-neutral-700' => ! $error,
                    'border-status-red' => $error])>
            <p id="{{ $id }}-note" class="mt-1.5 text-sm text-app-text-2" aria-live="polite">
                <span @if ($scoped) x-text="priceNote" @endif>{{ $scoped ? '' : $note }}</span>
                <span x-show="priceChanged" x-cloak class="ml-1 inline-flex items-center gap-1 rounded-full bg-status-amber-bg px-2 py-0.5 text-xs font-semibold text-status-amber"
                    x-text="'Changed from ' + basePriceText"></span>
            </p>
        </div>
    </x-ui.field>
</div>
