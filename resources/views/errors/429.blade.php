@extends('layouts.public')

@section('title', 'Too many requests')

@section('main')
    <x-layout.error-card code="429" title="Too many attempts" message="You sent too many requests in a short time. Please wait a minute and try again." />
@endsection
