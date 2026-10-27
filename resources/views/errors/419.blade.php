@extends('layouts.public')

@section('title', 'Page expired')

@section('main')
    <x-layout.error-card code="419" title="This page has expired" message="Your session timed out before the form was sent. Please go back, refresh the page and try again." />
@endsection
