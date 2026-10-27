@props(['action', 'on', 'name', 'label' => 'Published'])

{{-- A one-click on/off switch that posts to $action (the server flips the value and answers with a toast).
     The visible part is decorative; the screen-reader text says what it is and what pressing does. --}}
<form method="POST" action="{{ $action }}">
    @csrf
    <button type="submit" role="switch" aria-checked="{{ $on ? 'true' : 'false' }}"
        class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-control focus-ring">
        <span @class(['relative h-6 w-11 rounded-full transition-colors duration-200', 'bg-status-green' => $on, 'bg-neutral-300 dark:bg-neutral-600' => ! $on])>
            <span @class(['absolute top-0.5 size-5 rounded-full bg-white shadow transition-[left] duration-200', 'left-[22px]' => $on, 'left-0.5' => ! $on])></span>
        </span>
        <span class="sr-only">{{ $label }}: {{ $name }}, {{ $on ? 'on' : 'off' }}. Press to switch {{ $on ? 'off' : 'on' }}.</span>
    </button>
</form>
