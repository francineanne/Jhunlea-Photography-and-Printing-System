@extends('layouts.public')

@section('title', 'Page not found')

@section('main')
    <x-layout.error-card code="404" title="Page not found" message="We could not find the page you are looking for. It may have been moved, or the address may be wrong." />
@endsection
