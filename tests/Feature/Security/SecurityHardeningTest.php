<?php

namespace Tests\Feature\Security;

use App\Enums\UserRole;
use App\Http\Middleware\SecurityHeaders;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/** Phase 9A: headers, session cookie, mass assignment over HTTP, error pages, the new limiters and the upload folder. */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_web_response_carries_the_security_headers(): void
    {
        $owner = User::factory()->owner()->create();

        foreach ([fn () => $this->get('/'), fn () => $this->get('/no-such-page'), fn () => $this->get('/login'), fn () => $this->actingAs($owner)->get('/owner')] as $request) {
            $response = $request();
            foreach (SecurityHeaders::HEADERS as $name => $value) {
                $this->assertSame($value, $response->headers->get($name), "{$name} on {$response->getStatusCode()}");
            }
            // Never over plain http: a local address must not be pinned to https
            $this->assertFalse($response->headers->has('Strict-Transport-Security'));
            $this->assertFalse($response->headers->has('Content-Security-Policy'), 'No enforcing CSP (docs/SECURITY_AUDIT.md)');
        }

        $this->get('https://localhost/')->assertHeader('Strict-Transport-Security', SecurityHeaders::HSTS);
    }

    public function test_a_header_set_by_the_response_itself_is_kept(): void
    {
        Route::middleware('web')->get('/__test/framed', fn () => response('ok')->header('X-Frame-Options', 'DENY'));

        $this->get('/__test/framed')->assertHeader('X-Frame-Options', 'DENY')->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_the_session_cookie_is_http_only_lax_and_secure_with_an_https_address(): void
    {
        $this->assertTrue(config('session.http_only'));
        $this->assertSame('lax', config('session.same_site'));

        $secure = function (?string $appUrl, ?string $flag): mixed {
            $names = ['APP_URL', 'SESSION_SECURE_COOKIE'];
            $saved = [];
            foreach ($names as $name) {
                $saved[$name] = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null, getenv($name)];
            }
            foreach (array_combine($names, [$appUrl, $flag]) as $name => $value) {
                unset($_SERVER[$name], $_ENV[$name]);
                putenv($name);
                if ($value !== null) {
                    $_SERVER[$name] = $_ENV[$name] = $value;
                }
            }

            try {
                return (require config_path('session.php'))['secure'];
            } finally {
                foreach ($saved as $name => [$server, $env, $process]) {
                    unset($_SERVER[$name], $_ENV[$name]);
                    if ($server !== null) {
                        $_SERVER[$name] = $server;
                    }
                    if ($env !== null) {
                        $_ENV[$name] = $env;
                    }
                    putenv($process === false ? $name : "{$name}={$process}");
                }
            }
        };

        $this->assertTrue($secure('https://jhunlea.example', null));
        $this->assertFalse($secure('http://localhost:8000', null));
        $this->assertTrue($secure('http://localhost:8000', 'true'), 'SESSION_SECURE_COOKIE wins');
        $this->assertFalse($secure('https://jhunlea.example', 'false'));
    }

    public function test_only_the_app_url_host_is_trusted_outside_local(): void
    {
        $this->assertTrue(app(HttpKernel::class)->hasMiddleware(TrustHosts::class));

        $pattern = (new TrustHosts(app()))->hosts()[0];
        $this->assertMatchesRegularExpression('/'.$pattern.'/', parse_url(config('app.url'), PHP_URL_HOST));
        $this->assertDoesNotMatchRegularExpression('/'.$pattern.'/', 'evil.example');
    }

    public function test_registration_and_the_profile_form_cannot_set_role_or_walk_in(): void
    {
        $this->post('/register', [
            'name' => 'Maria Cruz', 'email' => 'maria@example.test', 'phone' => '0917 123 4567', 'password' => 'Password1', 'password_confirmation' => 'Password1',
            'role' => 'owner', 'is_walk_in' => '1', 'theme' => 'dark', 'report_view' => 'monthly',
        ])->assertRedirect('/my/dashboard');

        $maria = User::query()->where('email', 'maria@example.test')->sole();
        $this->assertSame(UserRole::Customer, $maria->role);
        $this->assertFalse($maria->is_walk_in);

        $this->actingAs($maria)->post(route('customer.account.profile'), [
            'name' => 'Maria C.', 'email' => 'maria@example.test', 'phone' => '0917 123 4567',
            'role' => 'owner', 'is_walk_in' => '1',
        ])->assertRedirect();

        $maria->refresh();
        $this->assertSame('Maria C.', $maria->name, 'The form itself still works');
        $this->assertSame(UserRole::Customer, $maria->role);
        $this->assertFalse($maria->is_walk_in);
    }

    public function test_production_errors_show_no_stack_trace_or_message(): void
    {
        config(['app.debug' => false]);
        Route::middleware('web')->get('/__test/boom', fn () => throw new RuntimeException('secret-marker-in-the-exception'));

        $page = $this->get('/__test/boom')->assertStatus(500);
        foreach (['secret-marker-in-the-exception', 'RuntimeException', 'Stack trace', base_path()] as $leak) {
            $page->assertDontSee($leak, false);
        }

        $json = $this->getJson('/__test/boom')->assertStatus(500);
        $this->assertSame(['message' => 'Server Error'], $json->json());
    }

    /**
     * The limiters added in Phase 9A (routes/web.php). Each counter is filled to one below its limit: the next request
     * still passes, the one after is 429, and another route's counter is untouched. The limit is read from the route
     * itself, so this follows any change of the numbers.
     */
    public function test_each_new_limiter_refuses_only_after_its_own_limit(): void
    {
        $owner = User::factory()->owner()->create();
        $customer = User::factory()->create();
        $names = ['logout', 'login-form', 'register-form', 'password-request-form', 'password-reset-form', 'owner-order-list', 'owner-order-show',
            'owner-service-list', 'owner-service-create', 'owner-service-store', 'owner-service-edit', 'owner-service-update', 'owner-service-destroy', 'owner-service-publish',
            'owner-option-store', 'owner-option-update', 'owner-option-destroy',
            'owner-package-list', 'owner-package-create', 'owner-package-store', 'owner-package-edit', 'owner-package-update', 'owner-package-destroy', 'owner-package-publish', 'owner-package-feature',
            'owner-package-image-upload', 'owner-package-image-update', 'owner-package-image-cover', 'owner-package-image-destroy',
            'owner-theme-list', 'owner-theme-create', 'owner-theme-store', 'owner-theme-edit', 'owner-theme-update', 'owner-theme-destroy', 'owner-theme-publish'];

        $found = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $throttle = collect($route->gatherMiddleware())->first(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:'));
            [$limit, , $name] = array_pad(explode(',', substr((string) $throttle, 9)), 3, null);
            if (! in_array($name, $names, true)) {
                continue;
            }
            $found[] = $name;

            $user = match (true) {
                str_starts_with($name, 'owner-') => $owner,
                $name === 'logout' => $customer,
                default => null,
            };
            $url = preg_replace('/\{[^}]+\}/', '999999', '/'.$route->uri());
            $method = $route->methods()[0];
            $key = $name.sha1($user !== null ? (string) $user->getAuthIdentifier() : '|127.0.0.1');

            RateLimiter::clear($key);
            for ($i = 1; $i < (int) $limit; $i++) {
                RateLimiter::hit($key, 60);
            }

            $request = function () use ($user, $method, $url) {
                auth()->logout();

                return $user === null ? $this->call($method, $url) : $this->actingAs($user)->call($method, $url);
            };

            $this->assertNotSame(429, $request()->status(), "{$name}: the request at the limit must still pass");
            $this->assertSame(429, $request()->status(), "{$name}: one more must be refused");
            RateLimiter::clear($key);
        }

        $this->assertEqualsCanonicalizing($names, $found, 'Every new limiter is on a route');

        // Using one up never touches another (each has its own name)
        $this->actingAs($owner)->get(route('owner.services.index'))->assertOk();
    }

    public function test_private_files_are_never_served_by_laravels_storage_route(): void
    {
        $this->assertFalse(config('filesystems.disks.local.serve'));
        $this->assertFalse(Route::has('storage.local'));
        $this->assertFalse(Route::has('storage.local.upload'));
    }

    public function test_nothing_executable_is_reachable_under_public(): void
    {
        $scripts = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(public_path(), \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(public_path()) + 1));
            if (! str_starts_with($relative, 'storage/') && preg_match('/\.(php\d?|phtml|phar|pl|py|cgi|sh)$/i', $relative) && $relative !== 'index.php') {
                $scripts[] = $relative;
            }
        }
        $this->assertSame([], $scripts, 'Only public/index.php may be a script');

        // The uploaded images folder (served as /storage) refuses scripts even if one ever got in
        $rules = (string) file_get_contents(storage_path('app/public/.htaccess'));
        $this->assertStringContainsString('Require all denied', $rules);
        $this->assertMatchesRegularExpression('/FilesMatch "[^"]*php/', $rules);
    }
}
