@extends('layouts.customer')

@section('title', 'Notifications')

@section('main')
    <x-notifications.page :items="$items" :page="$page" :counts="$counts" :unread-only="$unreadOnly" area="customer" />
@endsection
