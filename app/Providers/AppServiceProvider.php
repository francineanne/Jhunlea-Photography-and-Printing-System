<?php

namespace App\Providers;

use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Models\User;
use App\Support\PasswordRules;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Password::defaults(fn () => PasswordRules::defaults());

        // Polymorphic columns (payments.payable_type) store these short names, never a class name. Every model used in
        // a morph relation must be listed here
        Relation::enforceMorphMap([
            'printing_order' => PrintingOrder::class,
            'photography_booking' => PhotographyBooking::class,
            'user' => User::class,
        ]);
    }
}
