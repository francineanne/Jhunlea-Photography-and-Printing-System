<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Customer\DashboardController as CustomerDashboardController;
use App\Http\Controllers\Owner\DashboardController as OwnerDashboardController;
use App\Http\Controllers\Owner\ServiceController;
use App\Http\Controllers\Owner\ServiceOptionController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:20,1');

    Route::get('/register', [RegisterController::class, 'create'])->name('register');
    Route::post('/register', [RegisterController::class, 'store'])->middleware('throttle:10,1');

    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('password.email');

    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->name('password.update');
});

Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

Route::get('/my/dashboard', CustomerDashboardController::class)
    ->middleware(['auth', 'role:customer'])
    ->name('customer.dashboard');

Route::get('/owner', OwnerDashboardController::class)
    ->middleware(['auth', 'role:owner'])
    ->name('owner.dashboard');

Route::middleware(['auth', 'role:owner'])->prefix('owner/services')->name('owner.services.')->group(function () {
    Route::get('/', [ServiceController::class, 'index'])->name('index');
    Route::get('/create', [ServiceController::class, 'create'])->name('create');
    Route::post('/', [ServiceController::class, 'store'])->name('store');
    Route::get('/{service}/edit', [ServiceController::class, 'edit'])->name('edit');
    Route::put('/{service}', [ServiceController::class, 'update'])->name('update');
    Route::delete('/{service}', [ServiceController::class, 'destroy'])->name('destroy');
    Route::post('/{service}/publish', [ServiceController::class, 'publish'])->name('publish');

    Route::scopeBindings()->group(function () {
        Route::post('/{service}/options', [ServiceOptionController::class, 'store'])->name('options.store');
        Route::put('/{service}/options/{option}', [ServiceOptionController::class, 'update'])->name('options.update');
        Route::delete('/{service}/options/{option}', [ServiceOptionController::class, 'destroy'])->name('options.destroy');
    });
});

// Component gallery for developers. Hidden outside the local environment.
Route::get('/ui-kit', function () {
    abort_unless(app()->environment('local'), 404);

    return view('dev.ui-kit');
});

// Runs inside the web middleware group, so error pages know whether the visitor is signed in
Route::fallback(fn () => abort(404));
