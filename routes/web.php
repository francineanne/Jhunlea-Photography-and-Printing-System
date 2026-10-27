<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Component gallery for developers. Hidden outside the local environment.
Route::get('/ui-kit', function () {
    abort_unless(app()->environment('local'), 404);

    return view('dev.ui-kit');
});
