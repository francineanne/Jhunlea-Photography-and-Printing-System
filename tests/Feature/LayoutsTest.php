<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class LayoutsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_page_points_to_a_real_icon_so_the_browser_does_not_ask_for_favicon_ico(): void
    {
        $this->get('/')->assertSee('<link rel="icon" href="'.asset('favicon.svg').'" type="image/svg+xml">', false);
        $this->get('/login')->assertSee('rel="icon"', false);

        $this->assertGreaterThan(100, filesize(public_path('favicon.svg')));
        $this->assertStringContainsString('#C62828', file_get_contents(public_path('favicon.svg')));
    }

    public function test_home_page_shows_login_and_register_to_guests(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Capture it.')
            ->assertSee(route('login'), false)
            ->assertSee(route('register'), false)
            ->assertDontSee('Log out');
    }

    public function test_home_page_shows_dashboard_and_logout_to_signed_in_users(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertOk()
            ->assertSee('Log out')
            ->assertSee('href="/my/dashboard"', false)
            ->assertDontSee(route('register'), false);

        $this->actingAs(User::factory()->owner()->create())
            ->get('/')
            ->assertSee('href="/owner"', false);
    }

    public function test_customer_shell_shows_only_customer_navigation(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/my/dashboard')
            ->assertOk()
            ->assertSee('Skip to content')
            ->assertSee('id="main-content"', false)
            ->assertSee('aria-current="page"', false)
            ->assertSee('href="'.route('customer.dashboard').'"', false)
            ->assertDontSee('Overview')
            ->assertDontSee(route('owner.dashboard'), false);
    }

    public function test_owner_shell_shows_only_owner_navigation(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->get('/owner')
            ->assertOk()
            ->assertSee('Skip to content')
            ->assertSee('id="main-content"', false)
            ->assertSee('aria-current="page"', false)
            ->assertSee('Overview')
            ->assertSee('href="'.route('owner.dashboard').'"', false)
            ->assertDontSee('Dashboard')
            ->assertDontSee(route('customer.dashboard'), false);
    }

    public function test_dashboards_have_no_invented_content(): void
    {
        // The customer dashboard shows only real numbers: a new customer has no orders
        $this->actingAs(User::factory()->create())->get('/my/dashboard')
            ->assertSee('You have not sent a printing request yet.')
            ->assertDontSee('The shop needs more information');
        $this->actingAs(User::factory()->owner()->create())->get('/owner')->assertSee('Nothing here yet');
    }

    public function test_every_shell_has_a_logout_form_that_works(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/my/dashboard')
            ->assertSee('action="'.route('logout').'"', false);

        $this->actingAs(User::factory()->owner()->create())
            ->get('/owner')
            ->assertSee('action="'.route('logout').'"', false);

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_page_loader_is_only_on_the_public_and_auth_layouts(): void
    {
        $this->get('/')->assertSee('class="page-loader', false);
        $this->get('/login')->assertSee('class="page-loader', false);

        $this->actingAs(User::factory()->create())->get('/my/dashboard')->assertDontSee('page-loader', false);
        $this->actingAs(User::factory()->owner()->create())->get('/owner')->assertDontSee('page-loader', false);
    }

    public function test_unknown_url_shows_the_not_found_page(): void
    {
        $this->get('/no-such-page')
            ->assertNotFound()
            ->assertSee('Page not found')
            ->assertSee('Go back home');
    }

    public function test_error_pages_never_throw_when_the_user_cannot_be_loaded(): void
    {
        // A role value outside the enum makes the cast throw; the error page must still render
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['role' => 'something-else']);
        $this->actingAs($user->fresh());

        $this->get('/no-such-page')->assertNotFound()->assertSee('Page not found')->assertSee('Go back home');
    }

    public function test_unknown_urls_with_other_methods_show_a_styled_error_page(): void
    {
        $this->post('/no-such-page')->assertStatus(405)->assertSee('That action is not allowed here');
    }

    public function test_too_many_requests_view_renders(): void
    {
        $this->assertStringContainsString('Too many attempts', view('errors.429')->render());
    }

    public function test_not_found_page_sends_signed_in_users_to_their_own_dashboard(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/no-such-page')
            ->assertNotFound()
            ->assertSee('Go to my dashboard')
            ->assertSee('href="/my/dashboard"', false);
    }

    public function test_forbidden_pages_use_the_new_error_view(): void
    {
        $this->actingAs(User::factory()->create())->get('/owner')
            ->assertForbidden()
            ->assertSee('You cannot open this page')
            ->assertSee('href="/my/dashboard"', false);

        $this->actingAs(User::factory()->owner()->create())->get('/my/dashboard')
            ->assertForbidden()
            ->assertSee('You cannot open this page')
            ->assertSee('href="/owner"', false);
    }

    public function test_expired_page_view_renders_without_a_user(): void
    {
        $html = view('errors.419')->render();

        $this->assertStringContainsString('This page has expired', $html);
        $this->assertStringContainsString('Go back home', $html);
    }

    public function test_a_token_mismatch_shows_the_expired_page(): void
    {
        Route::middleware('web')->post('/_test-419', fn () => throw new TokenMismatchException);

        $this->post('/_test-419')->assertStatus(419)->assertSee('This page has expired');
    }

    public function test_server_error_view_is_static(): void
    {
        $html = view('errors.500')->render();

        $this->assertStringContainsString('Something went wrong', $html);
        // Nothing that can fail while rendering an error: no Vite assets, CSRF token or user data
        $this->assertStringNotContainsString('csrf-token', $html);
        $this->assertStringNotContainsString('/build/', $html);
        $this->assertStringNotContainsString('localhost:5173', $html);
    }

    public function test_an_uncaught_exception_shows_the_server_error_page(): void
    {
        config(['app.debug' => false]);
        Route::middleware('web')->get('/_test-500', fn () => throw new \RuntimeException('boom'));

        $this->get('/_test-500')->assertStatus(500)->assertSee('Something went wrong')->assertDontSee('boom');
    }

    public function test_login_posts_are_limited_per_ip(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->post('/login', ['email' => "someone{$i}@example.test", 'password' => 'wrong'])->assertStatus(302);
        }

        $this->post('/login', ['email' => 'another@example.test', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_seeder_runs_and_creates_no_users(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, User::count());
    }
}
