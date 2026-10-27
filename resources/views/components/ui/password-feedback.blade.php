@props(['mode' => 'meter'])

{{-- Live hints for a password field. Must sit inside an element with x-data="passwordStrength(...)" (see the register and reset pages).
     meter: four-segment bar and a verdict. checklist: the rules, each ticked when met. match: whether the confirmation is the same.
     Advisory only: the server decides. Without JavaScript the live parts stay hidden and the plain rule text shows instead. --}}
@if ($mode === 'meter')
    {{-- Always in the page (a live region that appears already filled is often not announced); only the verdict is spoken, and it changes only when the level changes --}}
    <span class="sr-only" aria-live="polite" x-text="password.length ? summary : ''"></span>
    <div x-cloak x-show="password.length" class="mt-2 space-y-1.5">
        <div class="grid h-1.5 grid-cols-4 gap-1.5" aria-hidden="true">
            @foreach ([1, 2, 3, 4] as $segment)
                <div class="rounded-full bg-neutral-200 transition-colors duration-300 dark:bg-white/15"
                    x-bind:class="{
                        'bg-status-red!': metCount >= {{ $segment }} && tier === 'weak',
                        'bg-status-amber!': metCount >= {{ $segment }} && tier === 'medium',
                        'bg-status-green!': metCount >= {{ $segment }} && tier === 'strong',
                    }"></div>
            @endforeach
        </div>
        <p aria-hidden="true" class="flex items-center gap-1.5 font-mono text-[11px] font-medium"
            x-bind:class="{ 'text-status-red': tier === 'weak', 'text-amber-700 dark:text-status-amber': tier === 'medium', 'text-green-700 dark:text-status-green': tier === 'strong' }">
            <span class="material-symbols-outlined text-[14px]" aria-hidden="true"
                x-text="{ weak: 'error', medium: 'info', strong: 'verified' }[tier]"></span>
            <span x-text="summary"></span>
        </p>
    </div>
@elseif ($mode === 'checklist')
    <ul x-cloak class="mt-2.5 space-y-1 rounded-lg bg-black/5 p-2 text-sm dark:bg-white/5" aria-label="Password rules">
        <template x-for="rule in results" x-bind:key="rule.id">
            <li class="flex items-center gap-1.5" x-bind:class="rule.met ? 'text-green-700 dark:text-status-green' : 'text-app-text-2'">
                <span class="material-symbols-outlined text-[16px]" aria-hidden="true" x-text="rule.met ? 'check_circle' : 'radio_button_unchecked'"></span>
                <span x-text="rule.label"></span>
                <span class="sr-only" x-text="rule.met ? '(met)' : '(not met yet)'"></span>
            </li>
        </template>
    </ul>
    <noscript><p class="mt-1.5 text-sm text-app-text-2">{{ \App\Support\PasswordRules::summary() }}</p></noscript>
@else
    {{-- Only a match is announced: a mismatch is the normal state while the confirmation is still being typed --}}
    <span class="sr-only" aria-live="polite" x-text="matches ? 'Passwords match' : ''"></span>
    <p x-cloak x-show="confirm.length" class="mt-1.5 flex items-center gap-1 text-sm"
        x-bind:class="matches ? 'text-green-700 dark:text-status-green' : 'text-status-red'">
        <span class="material-symbols-outlined text-[16px]" aria-hidden="true" x-text="matches ? 'done_all' : 'close'"></span>
        <span x-text="matches ? 'Passwords match' : 'Passwords do not match'"></span>
    </p>
@endif
