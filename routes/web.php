<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Customer\DashboardController as CustomerDashboardController;
use App\Http\Controllers\Customer\PrintingEstimateController;
use App\Http\Controllers\Customer\PrintingOrderController;
use App\Http\Controllers\Owner\DashboardController as OwnerDashboardController;
use App\Http\Controllers\Owner\PackageController;
use App\Http\Controllers\Owner\PackageImageController;
use App\Http\Controllers\Owner\ServiceController;
use App\Http\Controllers\Owner\ServiceOptionController;
use App\Http\Controllers\Owner\ThemeController;
use App\Http\Controllers\Public\HomeController as PublicHomeController;
use App\Http\Controllers\Public\PackageCompareController as PublicPackageCompareController;
use App\Http\Controllers\Public\PackageController as PublicPackageController;
use App\Http\Controllers\Public\PackageFinderController as PublicPackageFinderController;
use App\Http\Controllers\Public\ServiceController as PublicServiceController;
use Illuminate\Support\Facades\Route;

// Public pages: readable by anyone, and they only ever read published records
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/', PublicHomeController::class)->name('home');
    Route::get('/services', [PublicServiceController::class, 'index'])->name('services.index');
    Route::get('/packages', [PublicPackageController::class, 'index'])->name('packages.index');
    // These two come before {slug}; PhotographyPackage::RESERVED_SLUGS keeps a package from taking their addresses
    Route::get('/packages/compare', PublicPackageCompareController::class)->name('packages.compare');
    Route::get('/packages/finder', PublicPackageFinderController::class)->name('packages.finder');
    Route::get('/packages/{slug}', [PublicPackageController::class, 'show'])->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')->name('packages.show');
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

// The customer's printing order wizard. Every change is a POST; the order list and detail pages come in Phase 3C
Route::middleware(['auth', 'role:customer'])->prefix('my/printing')->name('customer.printing.')->group(function () {
    Route::get('/new', [PrintingOrderController::class, 'create'])->middleware('throttle:60,1')->name('create');
    Route::get('/estimate', PrintingEstimateController::class)->middleware('throttle:120,1')->name('estimate');
    Route::post('/', [PrintingOrderController::class, 'store'])->middleware('throttle:10,1')->name('store');
    Route::get('/{order}/placed', [PrintingOrderController::class, 'placed'])->whereNumber('order')->middleware('throttle:60,1')->name('placed');
});

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

Route::middleware(['auth', 'role:owner'])->prefix('owner/packages')->name('owner.packages.')->group(function () {
    Route::get('/', [PackageController::class, 'index'])->name('index');
    Route::get('/create', [PackageController::class, 'create'])->name('create');
    Route::post('/', [PackageController::class, 'store'])->name('store');
    Route::get('/{package}/edit', [PackageController::class, 'edit'])->name('edit');
    Route::put('/{package}', [PackageController::class, 'update'])->name('update');
    Route::delete('/{package}', [PackageController::class, 'destroy'])->name('destroy');
    Route::post('/{package}/publish', [PackageController::class, 'publish'])->name('publish');
    Route::post('/{package}/feature', [PackageController::class, 'feature'])->name('feature');

    Route::scopeBindings()->group(function () {
        Route::post('/{package}/images', [PackageImageController::class, 'store'])->name('images.store');
        Route::put('/{package}/images/{image}', [PackageImageController::class, 'update'])->name('images.update');
        Route::post('/{package}/images/{image}/cover', [PackageImageController::class, 'cover'])->name('images.cover');
        Route::delete('/{package}/images/{image}', [PackageImageController::class, 'destroy'])->name('images.destroy');
    });
});

Route::middleware(['auth', 'role:owner'])->prefix('owner/themes')->name('owner.themes.')->group(function () {
    Route::get('/', [ThemeController::class, 'index'])->name('index');
    Route::get('/create', [ThemeController::class, 'create'])->name('create');
    Route::post('/', [ThemeController::class, 'store'])->name('store');
    Route::get('/{theme}/edit', [ThemeController::class, 'edit'])->name('edit');
    Route::put('/{theme}', [ThemeController::class, 'update'])->name('update');
    Route::delete('/{theme}', [ThemeController::class, 'destroy'])->name('destroy');
    Route::post('/{theme}/publish', [ThemeController::class, 'publish'])->name('publish');
});

// Component gallery for developers. Hidden outside the local environment.
Route::get('/ui-kit', function () {
    abort_unless(app()->environment('local'), 404);

    return view('dev.ui-kit');
});

// Runs inside the web middleware group, so error pages know whether the visitor is signed in
Route::fallback(fn () => abort(404));
