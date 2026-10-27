@extends('layouts.public')

@section('title', 'Not allowed')

@section('main')
    <x-layout.error-card code="405" title="That action is not allowed here" message="This page cannot be opened this way. Please go back and use the buttons on the page." />
@endsection
