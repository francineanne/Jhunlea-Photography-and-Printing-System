@props(['record' => null, 'type' => null])

{{--
    "Message the studio" (7F): opens the chat panel on the Studio tab with a typed draft ("Hi, about PR-000001: "), which
    the customer reads and sends themselves; nothing is sent. The page already authorised `record` (the customer's own
    order or booking), and the server checks the about chip again on send. Without JavaScript the link opens the full
    chat page with the same draft.
--}}
@php
    $about = $record === null ? null : ['type' => $type, 'id' => $record->id, 'reference' => $record->reference_number];
    $draft = $about === null ? '' : 'Hi, about '.$about['reference'].': ';
    $href = route('customer.assistant.page', array_filter(['tab' => 'studio', 'about' => $about ? $about['type'].':'.$about['id'] : null]));
@endphp

<a href="{{ $href }}" x-on:click.prevent="$dispatch('chat:studio', @js(['draft' => $draft, 'about' => $about]))" data-message-studio
    {{ $attributes }}>{{ $slot }}</a>
