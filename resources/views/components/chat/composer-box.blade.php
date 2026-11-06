@props(['label', 'sendLabel', 'placeholder' => null, 'placeholderBind' => null, 'disabledBind' => null, 'icon' => 'arrow_upward'])

{{--
    The message box shared by every chat (7F-2): the customer panel and page, the owner's thread and the owner's Ask Lumi.
    The parent Alpine component provides draft, canSend, onKeydown($event) and resize(); the box grows with the text up
    to a limit and then scrolls, so it never covers the list. Send is the one filled button of the area.
--}}
<div {{ $attributes->class('flex items-end gap-1.5 rounded-control border border-app-border bg-app-card p-1 focus-within:border-brand focus-within:shadow-[0_0_0_3px_rgba(198,40,40,0.12)]') }}>
    <textarea x-ref="input" x-model="draft" x-on:keydown="onKeydown($event)" x-on:input="resize()" rows="1" maxlength="{{ (int) config('chat.message_max_chars') }}"
        aria-label="{{ $label }}" @if ($placeholder) placeholder="{{ $placeholder }}" @endif @if ($placeholderBind) x-bind:placeholder="{{ $placeholderBind }}" @endif @if ($disabledBind) x-bind:disabled="{{ $disabledBind }}" @endif
        class="max-h-36 min-h-11 min-w-0 flex-1 resize-none bg-transparent px-2 py-2.5 text-base leading-6 placeholder:text-app-text-2 focus:outline-none disabled:opacity-60 sm:text-sm"></textarea>
    <button type="submit" x-bind:disabled="!canSend" aria-label="{{ $sendLabel }}"
        class="grid size-11 shrink-0 place-items-center rounded-control bg-brand text-white transition-[background-color,transform] duration-150 hover:bg-brand-deep active:scale-95 disabled:cursor-not-allowed disabled:opacity-50 focus-ring motion-reduce:transition-none motion-reduce:active:scale-100">
        <x-ui.icon :name="$icon" />
    </button>
</div>
