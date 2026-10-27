@props(['label' => null, 'name' => null, 'id' => null, 'type' => 'text', 'hint' => null, 'error' => null, 'icon' => null, 'required' => false])

@php
    $id ??= 'field-'.Str::random(8);
    $error ??= ($name && isset($errors)) ? $errors->first($name) : null;
    $isPassword = $type === 'password';
@endphp

<x-ui.field :id="$id" :label="$label" :hint="$hint" :error="$error" :required="$required">
    <div class="relative" @if ($isPassword) x-data="{ show: false }" @endif>
        @if ($icon)
            <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-app-muted">
                <x-ui.icon :name="$icon" />
            </span>
        @endif

        <input id="{{ $id }}" type="{{ $type }}" @if ($name) name="{{ $name }}" @endif
            @required($required)
            @if ($error) aria-invalid="true" @endif
            @if ($error || $hint) aria-describedby="{{ $id }}-{{ $error ? 'error' : 'hint' }}" @endif
            @if ($isPassword) x-bind:type="show ? 'text' : 'password'" @endif
            {{ $attributes->class([
                'block h-11 w-full rounded-control border bg-app-card px-3.5 text-base text-app-text transition-[border-color,box-shadow] duration-150 sm:text-sm',
                'placeholder:text-app-muted field-focus disabled:cursor-not-allowed disabled:opacity-60',
                'pl-10' => $icon,
                'pr-12' => $isPassword,
                'border-neutral-200 dark:border-neutral-700' => ! $error,
                'border-status-red' => $error,
            ]) }}>

        @if ($isPassword)
            <button type="button" x-on:click="show = !show" x-bind:aria-pressed="show"
                x-bind:aria-label="show ? 'Hide password' : 'Show password'" aria-label="Show password"
                class="absolute inset-y-0 right-0 grid size-11 place-items-center rounded-control text-app-text-2 hover:text-app-text focus-ring">
                <x-ui.icon name="visibility" x-show="!show" />
                <x-ui.icon name="visibility_off" x-show="show" x-cloak />
            </button>
        @endif
    </div>
</x-ui.field>
