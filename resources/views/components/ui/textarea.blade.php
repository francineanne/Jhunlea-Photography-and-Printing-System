@props(['label' => null, 'name' => null, 'id' => null, 'hint' => null, 'error' => null, 'required' => false])

@php
    $id ??= 'field-'.Str::random(8);
    $error ??= ($name && isset($errors)) ? $errors->first($name) : null;
@endphp

<x-ui.field :id="$id" :label="$label" :hint="$hint" :error="$error" :required="$required">
    <textarea id="{{ $id }}" @if ($name) name="{{ $name }}" @endif
        @required($required)
        @if ($error) aria-invalid="true" @endif
        @if ($error || $hint) aria-describedby="{{ $id }}-{{ $error ? 'error' : 'hint' }}" @endif
        {{ $attributes->class([
            'block min-h-28 w-full resize-y rounded-control border bg-app-card px-3.5 py-3 text-base text-app-text transition-[border-color,box-shadow] duration-150 sm:text-sm',
            'placeholder:text-app-muted field-focus disabled:cursor-not-allowed disabled:opacity-60',
            'border-neutral-200 dark:border-neutral-700' => ! $error,
            'border-status-red' => $error,
        ]) }}>{{ $slot }}</textarea>
</x-ui.field>
