<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_is_shown_to_guests(): void
    {
        $this->get('/login')->assertOk()->assertSee('Welcome back');
    }

    public function test_customer_is_sent_to_their_dashboard_after_login(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/my/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    public function test_owner_is_sent_to_the_owner_area_after_login(): void
    {
        $owner = User::factory()->owner()->create();

        $this->post('/login', ['email' => $owner->email, 'password' => 'password'])->assertRedirect('/owner');
    }

    public function test_wrong_password_shows_the_generic_message(): void
    {
        $user = User::factory()->create();

        $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);

        $this->assertGuest();
    }

    public function test_unknown_email_gets_the_same_message_as_a_wrong_password(): void
    {
        $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'whatever1'])
            ->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);
    }

    public function test_login_is_throttled_after_five_failed_attempts(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
        }

        // Even the right password is refused while locked out
        $response = $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString('Too many login attempts', session('errors')->first('email'));
        $this->assertGuest();
    }

    public function test_throttle_ignores_email_case(): void
    {
        User::factory()->create(['email' => 'lock@example.test']);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'LOCK@example.test', 'password' => 'wrong']);
        }

        $this->post('/login', ['email' => 'lock@example.test', 'password' => 'password']);

        $this->assertGuest();
    }

    public function test_throttle_is_per_email_so_other_accounts_still_log_in(): void
    {
        $locked = User::factory()->create();
        $other = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $locked->email, 'password' => 'wrong']);
        }

        $this->post('/login', ['email' => $other->email, 'password' => 'password'])->assertRedirect('/my/dashboard');
    }

    public function test_a_successful_login_clears_the_failed_attempts(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 4; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
        }
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->post('/logout');

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/my/dashboard');
    }

    public function test_the_session_id_changes_after_login(): void
    {
        $user = User::factory()->create();
        $this->startSession();
        $before = session()->getId();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->assertNotSame($before, session()->getId());
    }

    public function test_signed_in_users_are_redirected_away_from_guest_pages(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (['/login', '/register', '/forgot-password'] as $url) {
            $this->get($url)->assertRedirect('/my/dashboard');
        }

        $this->actingAs(User::factory()->owner()->create());
        foreach (['/login', '/register', '/forgot-password'] as $url) {
            $this->get($url)->assertRedirect('/owner');
        }
    }

    public function test_the_old_email_is_kept_but_the_password_is_not(): void
    {
        $this->from('/login')->post('/login', ['email' => 'someone@example.test', 'password' => 'secret-value']);

        $this->followingRedirects()->get('/login')->assertSee('someone@example.test')->assertDontSee('secret-value');
    }
}
