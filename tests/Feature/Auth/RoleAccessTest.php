<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/my/dashboard')->assertRedirect('/login');
        $this->get('/owner')->assertRedirect('/login');
    }

    public function test_a_customer_gets_403_on_the_owner_area(): void
    {
        $this->actingAs(User::factory()->create())->get('/owner')->assertForbidden();
    }

    public function test_an_owner_gets_403_on_the_customer_dashboard(): void
    {
        $this->actingAs(User::factory()->owner()->create())->get('/my/dashboard')->assertForbidden();
    }

    public function test_each_role_reaches_its_own_dashboard(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'Cora Customer']))
            ->get('/my/dashboard')->assertOk()->assertSee('Welcome back, Cora');

        $this->actingAs(User::factory()->owner()->create(['name' => 'Olive Owner']))
            ->get('/owner')->assertOk()->assertSee(', Olive')->assertSee('Owner overview');
    }

    public function test_the_greeting_escapes_the_name(): void
    {
        $this->actingAs(User::factory()->create(['name' => '<script>alert(1)</script>']))
            ->get('/my/dashboard')->assertDontSee('<script>alert(1)</script>', false);
    }
}
