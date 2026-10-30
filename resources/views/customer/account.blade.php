@extends('layouts.customer')

@section('title', 'My account')

@section('main')
    <x-account.page :user="$user" :area="$area" :counts="$counts" />
@endsection
