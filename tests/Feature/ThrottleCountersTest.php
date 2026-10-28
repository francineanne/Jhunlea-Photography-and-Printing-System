<?php

namespace Tests\Feature;

use App\Enums\PrintingOrderStatus as Status;
use App\Models\PrintingOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Laravel keeps ONE counter per visitor (per user, or per IP for guests) for every throttle that is not named. The
 * sign-in forms and the owner's order actions each name their own counter, so using up one never blocks another.
 */
class ThrottleCountersTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_logins_never_block_registering_or_a_password_reset(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->post('/login', ['email' => "nobody{$i}@example.test", 'password' => 'wrong-password']);
        }
        $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'wrong-password'])->assertTooManyRequests();

        $this->post('/register', [])->assertSessionHasErrors();
        $this->post('/forgot-password', ['email' => 'someone@example.test'])->assertRedirect();
        $this->post('/reset-password', [])->assertSessionHasErrors();
    }

    public function test_password_reset_requests_never_block_logging_in(): void
    {
        $user = User::factory()->create(['email' => 'maria@example.test']);

        for ($i = 0; $i < 6; $i++) {
            $this->post('/forgot-password', ['email' => 'maria@example.test']);
        }
        $this->post('/forgot-password', ['email' => 'maria@example.test'])->assertTooManyRequests();

        $this->post('/login', ['email' => 'maria@example.test', 'password' => 'password'])->assertRedirect($user->role->home());
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_reset_password_form_is_rate_limited_on_its_own(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->post('/reset-password', ['token' => 'guess', 'email' => 'maria@example.test', 'password' => 'x', 'password_confirmation' => 'x']);
        }
        $this->post('/reset-password', ['token' => 'guess', 'email' => 'maria@example.test'])->assertTooManyRequests();

        $this->post('/register', [])->assertSessionHasErrors();
    }

    public function test_using_up_one_owner_action_leaves_the_others_open(): void
    {
        $owner = User::factory()->owner()->create();
        $busy = PrintingOrder::factory()->status(Status::Submitted)->create();
        $other = PrintingOrder::factory()->status(Status::UnderReview)->create();

        // The first review works; the next 29 are refused by the status table but still count
        for ($i = 0; $i < 30; $i++) {
            $this->actingAs($owner)->post(route('owner.orders.review', $busy));
        }
        $this->actingAs($owner)->post(route('owner.orders.review', $busy))->assertTooManyRequests();

        $this->actingAs($owner)->post(route('owner.orders.info', $other), ['message' => 'Please send a PDF.'])->assertSessionHas('status');
        $this->actingAs($owner)->post(route('owner.orders.accept', $other), ['confirmed_price' => '100'])->assertSessionHas('status');
        $this->assertSame(Status::Accepted, $other->fresh()->status);
    }

    public function test_every_throttled_form_and_action_names_its_own_counter(): void
    {
        $throttles = collect(Route::getRoutes()->getRoutes())
            ->mapWithKeys(fn ($route) => [$route->methods()[0].' '.$route->uri() => collect($route->gatherMiddleware())->first(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:'))])
            ->filter();

        // Every throttle is named, the public pages too (since Phase 4B), and no two routes share a name
        $named = $throttles;

        foreach (['GET /', 'GET packages', 'GET packages/{slug}', 'GET my/bookings/new', 'GET my/bookings/slots', 'POST my/bookings', 'GET my/bookings/{booking}/sent',
            'GET my/bookings', 'GET my/bookings/{booking}', 'POST my/bookings/{booking}/cancel', 'POST my/bookings/{booking}/accept', 'POST my/bookings/{booking}/another-time',
            'GET owner/bookings', 'GET owner/bookings/{booking}', 'POST owner/bookings/{booking}/confirm', 'POST owner/bookings/{booking}/propose', 'POST owner/bookings/{booking}/reject',
            'POST owner/bookings/{booking}/complete', 'POST owner/bookings/{booking}/cancel', 'POST owner/bookings/{booking}/price', 'POST owner/bookings/{booking}/notes',
            'GET booking-images/{image}', 'GET booking-images/{image}/download',
            'GET owner/schedule', 'GET owner/schedule/edit', 'POST owner/schedule/hours', 'POST owner/schedule/settings', 'POST owner/schedule/blocked',
            'DELETE owner/schedule/blocked/{blockedDate}'] as $route) {
            $this->assertArrayHasKey($route, $named->all(), "{$route} has no throttle");
        }

        foreach (['POST login', 'POST register', 'POST forgot-password', 'POST reset-password'] as $route) {
            $this->assertArrayHasKey($route, $named->all(), "{$route} has no named throttle");
        }

        foreach (['review', 'accept', 'reject', 'info', 'processing', 'ready', 'complete', 'cancel', 'price'] as $action) {
            $this->assertArrayHasKey("POST owner/printing-orders/{order}/{$action}", $named->all(), "{$action} has no named throttle");
        }

        $prefixes = $named->map(fn ($throttle) => explode(',', $throttle)[2] ?? null);
        $this->assertNotContains(null, $prefixes->all(), 'A throttle without a name shares the visitor counter');
        $this->assertSame($prefixes->count(), $prefixes->unique()->count(), 'Two routes share a counter');
    }
}
