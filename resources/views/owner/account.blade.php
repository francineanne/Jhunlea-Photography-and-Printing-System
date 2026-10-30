@extends('layouts.owner')

@section('title', 'Account')

@section('main')
    <x-account.page :user="$user" :area="$area" :counts="$counts" />
@endsection
