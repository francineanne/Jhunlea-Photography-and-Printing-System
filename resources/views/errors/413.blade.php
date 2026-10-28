@extends('layouts.public')

@section('title', 'Upload too large')

@section('main')
    <x-layout.error-card code="413" title="That upload was too large" message="The files you sent are bigger than this server accepts in one go ({{ ini_get('post_max_size') }} in total). Please go back and send fewer or smaller files." />
@endsection
