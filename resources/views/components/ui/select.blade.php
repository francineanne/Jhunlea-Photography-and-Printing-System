@props(['label' => null, 'name' => null, 'id' => null, 'hint' => null, 'error' => null, 'required' => false])

@php
    $id ??= 'field-'.Str::random(8);
    $error ??= ($name && isset($errors)) ? $errors->first($name) : null;
@endphp

<x-ui.field :id="$id" :label="$label" :hint="$hint" :error="$error" :required="$required">
    <div class="relative">
        <select id="{{ $id }}" @if ($name) name="{{ $name }}" @endif
            @required($required)
            @if ($error) aria-invalid="true" @endif
            @if ($error || $hint) aria-describedby="{{ $id }}-{{ $error ? 'error' : 'hint' }}" @endif
            {{ $attributes->class([
                'block h-11 w-full appearance-none rounded-control border bg-app-card pl-3.5 pr-10 text-base text-app-text transition-[border-color,box-shadow] duration-150 sm:text-sm',
                'field-focus disabled:cursor-not-allowed disabled:opacity-60',
                'border-neutral-300 dark:border-neutral-700' => ! $error,
                'border-status-red' => $error,
            ]) }}>
            {{ $slot }}
        </select>
        <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-app-text-2">
            <x-ui.icon name="expand_more" />
        </span>
    </div>
</x-ui.field>
