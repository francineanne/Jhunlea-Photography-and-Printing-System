@extends('layouts.owner')

@section('title', 'Delete question')

{{-- The delete confirm step when JavaScript is off (with JavaScript the list opens a confirm dialog instead). --}}
@section('main')
    <div class="mx-auto max-w-xl rounded-card border border-app-border bg-app-card p-5 shadow-sm sm:p-6 dark:shadow-none">
        <h1 class="font-display text-2xl font-semibold">Delete this question?</h1>
        <p class="mt-3 break-words text-app-text-2">“<strong class="text-app-text">{{ $faq->question }}</strong>” will be removed for good. The other questions keep their order.</p>
        <form method="POST" action="{{ route('owner.faqs.destroy', $faq) }}" class="mt-6 flex flex-wrap justify-end gap-2">
            @csrf
            @method('DELETE')
            <input type="hidden" name="confirm" value="1">
            <x-ui.button :href="route('owner.faqs.index')" variant="outline">Cancel</x-ui.button>
            <x-ui.button type="submit" variant="danger"><x-ui.icon name="delete" :size="18" />Delete question</x-ui.button>
        </form>
    </div>
@endsection
