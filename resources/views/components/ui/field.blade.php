@props(['id', 'label' => null, 'hint' => null, 'error' => null, 'required' => false])

{{-- Shared label / hint / error wrapper for input, select, textarea and checkbox --}}
<div class="w-full">
    @if ($label)
        <label for="{{ $id }}" class="mb-1.5 block text-sm font-semibold">
            {{ $label }}@if ($required)<span class="text-brand" aria-hidden="true"> *</span>@endif
        </label>
    @endif

    {{ $slot }}

    @if ($error)
        <p id="{{ $id }}-error" class="mt-1.5 flex items-start gap-1 text-sm text-status-red">
            <x-ui.icon name="error" :size="16" class="mt-0.5" />
            <span>{{ $error }}</span>
        </p>
    @elseif ($hint)
        <p id="{{ $id }}-hint" class="mt-1.5 text-sm text-app-text-2">{{ $hint }}</p>
    @endif
</div>
