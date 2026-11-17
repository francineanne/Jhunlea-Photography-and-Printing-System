@props(['text', 'label' => 'Copy'])

{{--
    Phase 10D: a small copy button for a reference number or the shop's GCash number. Uses the clipboard with the older
    copy command as the fallback, and answers with a "Copied" toast. Shown only when scripts run.
--}}
<button type="button" x-data="copyText(@js((string) $text))" x-cloak x-on:click="copy().then(() => copied && $dispatch('toast', { message: @js($label.': copied'), variant: 'success', timeout: 2500 }))"
    title="{{ $label }}" aria-label="{{ $label }}"
    {{ $attributes->class('inline-grid size-11 shrink-0 place-items-center rounded-control text-app-text-2 transition-colors hover:bg-black/5 hover:text-app-text focus-ring sm:size-9 dark:hover:bg-white/10') }}>
    <span class="material-symbols-outlined text-[18px]" aria-hidden="true" x-text="copied ? 'check' : 'content_copy'">content_copy</span>
</button>
