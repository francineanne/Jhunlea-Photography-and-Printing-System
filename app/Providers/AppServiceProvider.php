<?php

namespace App\Providers;

use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Models\User;
use App\Services\NotificationFeed;
use App\Support\PasswordRules;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\View;
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

        // The bell of the customer and owner shells: the unread count and the latest few, once per page (Phase 5B)
        View::composer(['layouts.customer', 'layouts.owner'], function ($view) {
            $user = auth()->user();
            $view->with('bell', $user ? app(NotificationFeed::class)->bell($user) : ['unread' => 0, 'items' => collect()]);
        });
    }
}
