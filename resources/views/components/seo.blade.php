@props(['title', 'description', 'image' => null, 'canonical' => null, 'noindex' => false])

{{-- Search and sharing basics for a public page. The canonical address never carries the query string, so filtered lists do not count as separate pages. --}}
@php
    $canonical ??= url()->current();
    $name = config('app.name');
@endphp

@push('head')
    <meta name="description" content="{{ $description }}">
    <link rel="canonical" href="{{ $canonical }}">
    @if ($noindex)
        <meta name="robots" content="noindex, follow">
    @endif
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $name }}">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $canonical }}">
    @if ($image)
        <meta property="og:image" content="{{ $image }}">
    @endif
    <meta name="twitter:card" content="{{ $image ? 'summary_large_image' : 'summary' }}">
@endpush
