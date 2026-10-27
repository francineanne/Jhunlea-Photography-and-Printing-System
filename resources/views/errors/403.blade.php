@extends('layouts.public')

@section('title', 'Access denied')

@section('main')
    <x-layout.error-card code="403" title="You cannot open this page" message="Your account does not have access to this page." />
@endsection
