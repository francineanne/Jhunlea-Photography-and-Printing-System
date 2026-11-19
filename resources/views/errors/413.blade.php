@extends('layouts.public')

@section('title', 'Upload too large')

@section('main')
    <x-layout.error-card code="413" title="That upload was too large" message="The files you sent are too big to send at once. Please go back and send fewer or smaller files." />
@endsection
