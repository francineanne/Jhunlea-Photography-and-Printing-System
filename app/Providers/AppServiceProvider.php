<?php

namespace App\Providers;

use App\Models\Faq;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Models\User;
use App\Services\NotificationFeed;
use App\Support\PasswordRules;
use App\Support\ShopSettings;
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
        // The business info and upload limits are read once per request (Phase 6B)
        $this->app->scoped(ShopSettings::class);
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

        // The public footer (Phase 6B): the business details the owner filled in (never a placeholder) and the FAQ link
        // only when a question is published. rescue(): this layout also wraps the error pages, which must never throw
        View::composer('layouts.public', function ($view) {
            $view->with('shop', rescue(fn () => app(ShopSettings::class)->business(), ['shop_name' => null, 'address' => null, 'phone' => null, 'email' => null], false));
            $view->with('hasFaq', rescue(fn () => Faq::query()->published()->exists(), false, false));
        });

        // Error pages stay as simple as possible: the public layout leaves the floating chat out of them (Phase 7C)
        View::composer(['errors.*', 'errors::*'], fn ($view) => $view->with('isErrorPage', true));

        // The bell of the customer and owner shells: the unread count and the latest few, once per page (Phase 5B)
        View::composer(['layouts.customer', 'layouts.owner'], function ($view) {
            $user = auth()->user();
            $view->with('bell', $user ? app(NotificationFeed::class)->bell($user) : ['unread' => 0, 'items' => collect()]);
        });
    }
}
