<?php

namespace App\Providers;

use App\Models\Faq;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Models\User;
use App\Services\Assistant\GuardStats;
use App\Services\Chat\StudioInbox;
use App\Services\NotificationFeed;
use App\Support\PasswordRules;
use App\Support\PaymentRules;
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
        // Phase 7E-2e: the reply guards' removal counts of this request (counts only, no text)
        $this->app->scoped(GuardStats::class);
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
        // Phase 10G: the customer pages show the same footer
        View::composer(['layouts.public', 'layouts.customer'], function ($view) {
            $view->with('shop', rescue(fn () => app(ShopSettings::class)->business(), ['shop_name' => null, 'address' => null, 'phone' => null, 'email' => null, 'opening_hours' => null, 'facebook_url' => null], false));
            // Phase 10D: a page that already read the published questions (the landing page) shares the answer
            $view->with('hasFaq', view()->shared('hasFaqKnown') ?? rescue(fn () => Faq::query()->published()->exists(), false, false));
        });

        // Phase 10D: the one short, current payment line (top bar of the public pages, footer of the customer pages)
        View::composer(['layouts.public', 'layouts.customer', 'owner.dashboard'], fn ($view) => $view->with('policyLine', rescue(fn () => app(PaymentRules::class)->shortLine(), 'No online payment. Pay at the shop in cash or by GCash.', false)));

        // Error pages stay as simple as possible: the public layout leaves the floating chat out of them (Phase 7C)
        View::composer(['errors.*', 'errors::*'], fn ($view) => $view->with('isErrorPage', true));

        // The chat's fallback offers the FAQ only when a question is published, like the public footer (Phase 7D)
        View::composer('components.chat.rows', fn ($view) => $view->with('faqUrl', rescue(fn () => Faq::query()->published()->exists() ? route('faq') : null, null, false)));

        // The owner's sidebar: how many studio threads wait for a reply (Phase 7D), one query per page
        View::composer('layouts.owner', fn ($view) => $view->with('unanswered', rescue(fn () => app(StudioInbox::class)->unansweredCount(), 0, false)));

        // The bell of the customer and owner shells: the unread count and the latest few, once per page (Phase 5B)
        View::composer(['layouts.customer', 'layouts.owner'], function ($view) {
            $user = auth()->user();
            $view->with('bell', $user ? app(NotificationFeed::class)->bell($user) : ['unread' => 0, 'items' => collect()]);
        });
    }
}
