<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\BookingReferenceImageController;
use App\Http\Controllers\Customer\BookingSlotsController;
use App\Http\Controllers\Customer\DashboardController as CustomerDashboardController;
use App\Http\Controllers\Customer\MyBookingController;
use App\Http\Controllers\Customer\MyOrderController;
use App\Http\Controllers\Customer\PhotographyBookingController;
use App\Http\Controllers\Customer\PrintingEstimateController;
use App\Http\Controllers\Customer\PrintingOrderController;
use App\Http\Controllers\Customer\RequestHistoryController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrderFileDownloadController;
use App\Http\Controllers\Owner\CustomerController;
use App\Http\Controllers\Owner\DashboardController as OwnerDashboardController;
use App\Http\Controllers\Owner\FaqController as OwnerFaqController;
use App\Http\Controllers\Owner\PackageController;
use App\Http\Controllers\Owner\PackageImageController;
use App\Http\Controllers\Owner\PaymentActionController;
use App\Http\Controllers\Owner\PaymentController;
use App\Http\Controllers\Owner\PhotographyBookingActionController;
use App\Http\Controllers\Owner\PhotographyBookingController as OwnerPhotographyBookingController;
use App\Http\Controllers\Owner\PrintingOrderActionController;
use App\Http\Controllers\Owner\PrintingOrderController as OwnerPrintingOrderController;
use App\Http\Controllers\Owner\ReportController;
use App\Http\Controllers\Owner\ScheduleController;
use App\Http\Controllers\Owner\ServiceController;
use App\Http\Controllers\Owner\ServiceOptionController;
use App\Http\Controllers\Owner\SettingsController;
use App\Http\Controllers\Owner\ThemeController;
use App\Http\Controllers\Public\FaqController as PublicFaqController;
use App\Http\Controllers\Public\HomeController as PublicHomeController;
use App\Http\Controllers\Public\PackageCompareController as PublicPackageCompareController;
use App\Http\Controllers\Public\PackageController as PublicPackageController;
use App\Http\Controllers\Public\PackageFinderController as PublicPackageFinderController;
use App\Http\Controllers\Public\ServiceController as PublicServiceController;
use Illuminate\Support\Facades\Route;

// Public pages: readable by anyone, and they only ever read published records. Each page has its own counter
Route::get('/', PublicHomeController::class)->middleware('throttle:60,1,public-home')->name('home');
Route::get('/services', [PublicServiceController::class, 'index'])->middleware('throttle:60,1,public-services')->name('services.index');
Route::get('/packages', [PublicPackageController::class, 'index'])->middleware('throttle:60,1,public-packages')->name('packages.index');
// These two come before {slug}; PhotographyPackage::RESERVED_SLUGS keeps a package from taking their addresses
Route::get('/packages/compare', PublicPackageCompareController::class)->middleware('throttle:60,1,public-packages-compare')->name('packages.compare');
Route::get('/packages/finder', PublicPackageFinderController::class)->middleware('throttle:60,1,public-packages-finder')->name('packages.finder');
Route::get('/packages/{slug}', [PublicPackageController::class, 'show'])->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')->middleware('throttle:60,1,public-package-show')->name('packages.show');
Route::get('/faq', PublicFaqController::class)->middleware('throttle:60,1,public-faq')->name('faq');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    // Each throttle names its own counter, so failed logins never block registering or a password reset
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:20,1,login');

    Route::get('/register', [RegisterController::class, 'create'])->name('register');
    Route::post('/register', [RegisterController::class, 'store'])->middleware('throttle:10,1,register');

    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:6,1,password-email')
        ->name('password.email');

    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->middleware('throttle:6,1,password-reset')->name('password.update');
});

Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

Route::get('/my/dashboard', CustomerDashboardController::class)
    ->middleware(['auth', 'role:customer', 'throttle:60,1,customer-dashboard'])
    ->name('customer.dashboard');

// All of the customer's printing orders and bookings in one list (Phase 5C)
Route::get('/my/requests', RequestHistoryController::class)
    ->middleware(['auth', 'role:customer', 'throttle:60,1,customer-requests'])
    ->name('customer.requests.index');

// The customer's printing order wizard. Every change is a POST.
// Each throttle has its own name (the third value): without it Laravel keeps ONE counter per user for every
// throttled route, so typing in the wizard (live estimate) could use up the limit for sending the order
Route::middleware(['auth', 'role:customer'])->prefix('my/printing')->name('customer.printing.')->group(function () {
    Route::get('/new', [PrintingOrderController::class, 'create'])->middleware('throttle:60,1,printing-new')->name('create');
    Route::get('/estimate', PrintingEstimateController::class)->middleware('throttle:120,1,printing-estimate')->name('estimate');
    Route::post('/', [PrintingOrderController::class, 'store'])->middleware('throttle:10,1,printing-store')->name('store');
    Route::get('/{order}/placed', [PrintingOrderController::class, 'placed'])->whereNumber('order')->middleware('throttle:60,1,printing-placed')->name('placed');

    // My orders (Phase 3C)
    Route::get('/', [MyOrderController::class, 'index'])->middleware('throttle:60,1,printing-list')->name('index');
    Route::get('/{order}', [MyOrderController::class, 'show'])->whereNumber('order')->middleware('throttle:60,1,printing-show')->name('show');
    Route::post('/{order}/cancel', [MyOrderController::class, 'cancel'])->whereNumber('order')->middleware('throttle:10,1,printing-cancel')->name('cancel');
    Route::post('/{order}/files', [MyOrderController::class, 'addFiles'])->whereNumber('order')->middleware('throttle:10,1,printing-files-add')->name('files.store');
    // The file is looked up through the order, so it must belong to it (a POST form with _method=DELETE)
    Route::delete('/{order}/files/{file}', [MyOrderController::class, 'removeFile'])->whereNumber(['order', 'file'])->middleware('throttle:10,1,printing-files-remove')->name('files.destroy');
});

// The customer's photoshoot booking wizard (Phase 4B). The calendar asks /slots for free times; the wizard sends one POST
Route::middleware(['auth', 'role:customer'])->prefix('my/bookings')->name('customer.bookings.')->group(function () {
    Route::get('/new', [PhotographyBookingController::class, 'create'])->middleware('throttle:60,1,booking-new')->name('create');
    Route::get('/slots', BookingSlotsController::class)->middleware('throttle:120,1,booking-slots')->name('slots');
    Route::post('/', [PhotographyBookingController::class, 'store'])->middleware('throttle:10,1,booking-store')->name('store');
    Route::get('/{booking}/sent', [PhotographyBookingController::class, 'sent'])->whereNumber('booking')->middleware('throttle:60,1,booking-sent')->name('sent');

    // My bookings (Phase 4C): the list, one booking, cancelling and answering a proposed time
    Route::get('/', [MyBookingController::class, 'index'])->middleware('throttle:60,1,booking-list')->name('index');
    Route::whereNumber('booking')->controller(MyBookingController::class)->group(function () {
        Route::get('/{booking}', 'show')->middleware('throttle:60,1,booking-show')->name('show');
        Route::post('/{booking}/cancel', 'cancel')->middleware('throttle:10,1,booking-cancel')->name('cancel');
        Route::post('/{booking}/accept', 'accept')->middleware('throttle:10,1,booking-accept')->name('accept');
        Route::post('/{booking}/another-time', 'anotherTime')->middleware('throttle:10,1,booking-another-time')->name('another-time');
    });
});

// The owner's photography bookings (Phase 4C). Every action is a POST that goes through BookingTransitionService
Route::middleware(['auth', 'role:owner'])->prefix('owner/bookings')->name('owner.bookings.')->group(function () {
    Route::get('/', [OwnerPhotographyBookingController::class, 'index'])->middleware('throttle:120,1,owner-booking-list')->name('index');
    Route::get('/{booking}', [OwnerPhotographyBookingController::class, 'show'])->whereNumber('booking')->middleware('throttle:120,1,owner-booking-show')->name('show');

    // 30 a minute for each action, each with its own counter
    Route::whereNumber('booking')->controller(PhotographyBookingActionController::class)->group(function () {
        Route::post('/{booking}/confirm', 'confirm')->middleware('throttle:30,1,owner-booking-confirm')->name('confirm');
        Route::post('/{booking}/propose', 'propose')->middleware('throttle:30,1,owner-booking-propose')->name('propose');
        Route::post('/{booking}/reject', 'reject')->middleware('throttle:30,1,owner-booking-reject')->name('reject');
        Route::post('/{booking}/complete', 'complete')->middleware('throttle:30,1,owner-booking-complete')->name('complete');
        Route::post('/{booking}/cancel', 'cancel')->middleware('throttle:30,1,owner-booking-cancel')->name('cancel');
        Route::post('/{booking}/price', 'price')->middleware('throttle:30,1,owner-booking-price')->name('price');
        Route::post('/{booking}/notes', 'notes')->middleware('throttle:30,1,owner-booking-notes')->name('notes');
    });

    // Payments (Phase 5A): a photoshoot is paid on the day, once it is confirmed
    Route::whereNumber(['booking', 'payment'])->controller(PaymentActionController::class)->group(function () {
        Route::post('/{booking}/payments', 'recordBooking')->middleware('throttle:20,1,owner-booking-payment-record')->name('payments.store');
        Route::post('/{booking}/payments/{payment}/void', 'voidBooking')->middleware('throttle:20,1,owner-booking-payment-void')->name('payments.void');
    });
});

// The owner's schedule (Phase 4D): the calendar, and the editor of opening hours, booking settings and blocked dates
Route::middleware(['auth', 'role:owner'])->prefix('owner/schedule')->name('owner.schedule.')->controller(ScheduleController::class)->group(function () {
    Route::get('/', 'index')->middleware('throttle:120,1,owner-schedule-calendar')->name('index');
    Route::get('/edit', 'edit')->middleware('throttle:60,1,owner-schedule-edit')->name('edit');
    Route::post('/hours', 'hours')->middleware('throttle:20,1,owner-schedule-hours')->name('hours');
    Route::post('/settings', 'settings')->middleware('throttle:20,1,owner-schedule-settings')->name('settings');
    Route::post('/blocked', 'block')->middleware('throttle:20,1,owner-schedule-block')->name('block');
    Route::delete('/blocked/{blockedDate}', 'unblock')->whereNumber('blockedDate')->middleware('throttle:30,1,owner-schedule-unblock')->name('unblock');
});

// Notifications (Phase 5B): each area reads and marks only its own person's rows. Marking is always a POST
foreach (['customer' => 'my', 'owner' => 'owner'] as $area => $prefix) {
    Route::middleware(['auth', "role:{$area}"])->prefix("{$prefix}/notifications")->name("{$area}.notifications.")->controller(NotificationController::class)->group(function () use ($area) {
        Route::get('/', 'index')->middleware("throttle:60,1,{$area}-notifications")->name('index');
        Route::post('/read-all', 'readAll')->middleware("throttle:20,1,{$area}-notifications-read-all")->name('read-all');
        Route::whereUuid('notification')->group(function () use ($area) {
            Route::get('/{notification}', 'go')->middleware("throttle:60,1,{$area}-notification-go")->name('go');
            Route::post('/{notification}/open', 'open')->middleware("throttle:60,1,{$area}-notification-open")->name('open');
            Route::post('/{notification}/read', 'read')->middleware("throttle:60,1,{$area}-notification-read")->name('read');
        });
    });
}

// The owner's payments ledger (Phase 5A): recorded and voided payments, recorded totals and what is still unpaid
Route::get('/owner/payments', [PaymentController::class, 'index'])
    ->middleware(['auth', 'role:owner', 'throttle:120,1,owner-payments-ledger'])
    ->name('owner.payments.index');

// The owner's customers (Phase 6A): the directory and one customer's page, read only. An id that is not a customer is 404
Route::middleware(['auth', 'role:owner'])->prefix('owner/customers')->name('owner.customers.')->controller(CustomerController::class)->group(function () {
    Route::get('/', 'index')->middleware('throttle:120,1,owner-customers')->name('index');
    Route::get('/{customer}', 'show')->whereNumber('customer')->middleware('throttle:120,1,owner-customer-show')->name('show');
});

// The owner's Settings (Phase 6B): business info and upload limits are saved here; hours and booking rules are only shown
Route::middleware(['auth', 'role:owner'])->prefix('owner/settings')->name('owner.settings.')->controller(SettingsController::class)->group(function () {
    Route::get('/', 'index')->middleware('throttle:60,1,owner-settings')->name('index');
    Route::post('/business', 'business')->middleware('throttle:20,1,owner-settings-business')->name('business');
    Route::post('/uploads', 'uploads')->middleware('throttle:20,1,owner-settings-uploads')->name('uploads');
    Route::post('/uploads/reset', 'resetUploads')->middleware('throttle:10,1,owner-settings-uploads-reset')->name('uploads.reset');
});

// The owner's FAQ editor (Phase 6B). Every change goes through FaqService; moves, show/hide and delete are plain POST forms
Route::middleware(['auth', 'role:owner'])->prefix('owner/faqs')->name('owner.faqs.')->controller(OwnerFaqController::class)->group(function () {
    Route::get('/', 'index')->middleware('throttle:60,1,owner-faqs')->name('index');
    Route::get('/create', 'create')->middleware('throttle:60,1,owner-faq-create')->name('create');
    Route::post('/', 'store')->middleware('throttle:20,1,owner-faq-store')->name('store');
    Route::whereNumber('faq')->group(function () {
        Route::get('/{faq}/edit', 'edit')->middleware('throttle:60,1,owner-faq-edit')->name('edit');
        Route::put('/{faq}', 'update')->middleware('throttle:20,1,owner-faq-update')->name('update');
        Route::post('/{faq}/publish', 'publish')->middleware('throttle:30,1,owner-faq-publish')->name('publish');
        Route::post('/{faq}/move', 'move')->middleware('throttle:60,1,owner-faq-move')->name('move');
        Route::get('/{faq}/delete', 'confirmDelete')->middleware('throttle:60,1,owner-faq-confirm-delete')->name('confirm-delete');
        Route::delete('/{faq}', 'destroy')->middleware('throttle:20,1,owner-faq-destroy')->name('destroy');
    });
});

// The owner's reports (Phase 6A): requests created and payments received in a period
Route::get('/owner/reports', [ReportController::class, 'index'])
    ->middleware(['auth', 'role:owner', 'throttle:60,1,owner-reports'])
    ->name('owner.reports.index');

// A reference image of a booking, for the owner or the customer whose booking it is (BookingReferenceImagePolicy).
// Shown in the page only when its content is a real JPEG, PNG or WebP image; the download link always downloads
Route::middleware('auth')->prefix('booking-images')->name('booking-images.')->whereNumber('image')->controller(BookingReferenceImageController::class)->group(function () {
    Route::get('/{image}', 'show')->middleware('throttle:240,1,booking-image-show')->name('show');
    Route::get('/{image}/download', 'download')->middleware('throttle:30,1,booking-image-download')->name('download');
});

// The owner's printing orders. Every action is a POST that goes through StatusTransitionService
Route::middleware(['auth', 'role:owner'])->prefix('owner/printing-orders')->name('owner.orders.')->group(function () {
    Route::get('/', [OwnerPrintingOrderController::class, 'index'])->name('index');
    Route::get('/{order}', [OwnerPrintingOrderController::class, 'show'])->whereNumber('order')->name('show');

    // 30 a minute for each action, each with its own counter, so a busy run of one action never blocks another
    Route::whereNumber('order')->controller(PrintingOrderActionController::class)->group(function () {
        Route::post('/{order}/review', 'review')->middleware('throttle:30,1,owner-order-review')->name('review');
        Route::post('/{order}/accept', 'accept')->middleware('throttle:30,1,owner-order-accept')->name('accept');
        Route::post('/{order}/reject', 'reject')->middleware('throttle:30,1,owner-order-reject')->name('reject');
        Route::post('/{order}/info', 'requestInfo')->middleware('throttle:30,1,owner-order-info')->name('info');
        Route::post('/{order}/processing', 'processing')->middleware('throttle:30,1,owner-order-processing')->name('processing');
        Route::post('/{order}/ready', 'ready')->middleware('throttle:30,1,owner-order-ready')->name('ready');
        Route::post('/{order}/complete', 'complete')->middleware('throttle:30,1,owner-order-complete')->name('complete');
        Route::post('/{order}/cancel', 'cancel')->middleware('throttle:30,1,owner-order-cancel')->name('cancel');
        Route::post('/{order}/price', 'price')->middleware('throttle:30,1,owner-order-price')->name('price');
    });

    // Payments (Phase 5A): recorded and voided only through PaymentService
    Route::whereNumber(['order', 'payment'])->controller(PaymentActionController::class)->group(function () {
        Route::post('/{order}/payments', 'recordOrder')->middleware('throttle:20,1,owner-order-payment-record')->name('payments.store');
        Route::post('/{order}/payments/{payment}/void', 'voidOrder')->middleware('throttle:20,1,owner-order-payment-void')->name('payments.void');
    });
});

// A file of a printing order, for the owner or the customer who sent it (OrderFilePolicy). Always a download
Route::get('/files/{file}', OrderFileDownloadController::class)
    ->whereNumber('file')
    ->middleware(['auth', 'throttle:30,1,files-download'])
    ->name('files.download');

Route::get('/owner', OwnerDashboardController::class)
    ->middleware(['auth', 'role:owner', 'throttle:120,1,owner-dashboard'])
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
