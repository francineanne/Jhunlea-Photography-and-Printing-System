@props(['label' => null, 'name' => null, 'id' => null, 'hint' => null, 'error' => null])

@php
    $id ??= 'field-'.Str::random(8);
    $error ??= ($name && isset($errors)) ? $errors->first($name) : null;
@endphp

<x-ui.field :id="$id" :hint="$hint" :error="$error">
    {{-- The whole label row is the tap target (44px tall) --}}
    <label for="{{ $id }}" class="flex min-h-11 cursor-pointer items-start gap-3 py-2.5 text-sm">
        <input id="{{ $id }}" type="checkbox" @if ($name) name="{{ $name }}" @endif
            @if ($error) aria-invalid="true" @endif
            @if ($error || $hint) aria-describedby="{{ $id }}-{{ $error ? 'error' : 'hint' }}" @endif
            {{ $attributes->class('ui-checkbox mt-px') }}>
        <span>{{ $label ?? $slot }}</span>
    </label>
</x-ui.field>
