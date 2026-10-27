<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_post_logout_signs_the_user_out(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_logout_does_nothing_over_get(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/logout')->assertStatus(405);

        $this->assertAuthenticated();
    }

    public function test_logout_is_a_post_route_in_the_web_group_so_csrf_applies(): void
    {
        $route = Route::getRoutes()->getByName('logout');

        $this->assertSame(['POST'], $route->methods());
        $this->assertContains('web', $route->gatherMiddleware());
    }

    public function test_guests_cannot_use_logout(): void
    {
        $this->post('/logout')->assertRedirect('/login');
    }
}
