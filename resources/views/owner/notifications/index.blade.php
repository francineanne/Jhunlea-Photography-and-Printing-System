@extends('layouts.owner')

@section('title', 'Notifications')

@section('main')
    <x-notifications.page :items="$items" :page="$page" :counts="$counts" :unread-only="$unreadOnly" :system-only="$systemOnly" area="owner" />
@endsection
