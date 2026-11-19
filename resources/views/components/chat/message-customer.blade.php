@props(['customer', 'record' => null, 'type' => null, 'size' => 'sm'])

{{--
    Phase 10F: it sits in the action slot of the page header (customer, order and booking pages): its own width beside
    the name when the header has room, a full-width row under it when the header is narrow (container queries; the
    header is the @container).
    Phase 10E: the owner's "Message" button (a customer page) or "Message customer about this" (an order or booking page).
    It opens or creates the customer's Studio thread; with a record, that record is attached to the owner's next
    message. A walk-in has no account to read a message: the phone is offered instead.
--}}
@if ($customer && ! $customer->isWalkIn())
    <form method="POST" action="{{ route('owner.messages.start') }}" {{ $attributes->class('inline') }}>
        @csrf
        <input type="hidden" name="customer" value="{{ $customer->id }}">
        @if ($record)
            <input type="hidden" name="about_type" value="{{ $type }}">
            <input type="hidden" name="about_id" value="{{ $record->id }}">
        @endif
        <x-ui.button type="submit" :size="$size" class="w-full @xl:w-auto"><x-ui.icon name="forum" :size="16" />{{ $record ? 'Message customer about this' : 'Message' }}</x-ui.button>
    </form>
@elseif ($customer)
    <p {{ $attributes->class('text-sm text-app-text-2 @xl:max-w-xs @xl:text-right') }} data-walk-in-no-chat>Walk-in customers have no account to message.@if (filled($customer->phone)) Call <a href="{{ \App\Support\PhilippineMobile::telHref($customer->phone) }}" class="font-semibold text-brand underline-offset-2 hover:underline focus-ring">{{ \App\Support\PhilippineMobile::display($customer->phone) }}</a>.@endif</p>
@endif
