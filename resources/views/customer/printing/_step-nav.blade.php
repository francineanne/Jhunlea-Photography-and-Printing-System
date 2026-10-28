{{-- Back and Continue for steps 1 to 3. They only exist with scripts: without them every step is already on the page. --}}
<div class="mt-6 border-t border-app-border pt-5" x-cloak>
    <p class="mb-3 flex items-start gap-1 text-sm text-status-red" role="alert" x-show="problem && step === {{ $number }}">
        <x-ui.icon name="error" :size="16" class="mt-0.5 shrink-0" /><span x-text="problem"></span>
    </p>
    <div class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
        @if ($number > 1)
            <x-ui.button variant="outline" x-on:click="back()"><x-ui.icon name="arrow_back" :size="18" />Back</x-ui.button>
        @else
            <span class="hidden sm:block"></span>
        @endif
        <x-ui.button x-on:click="next()">Continue<x-ui.icon name="arrow_forward" :size="18" /></x-ui.button>
    </div>
</div>
