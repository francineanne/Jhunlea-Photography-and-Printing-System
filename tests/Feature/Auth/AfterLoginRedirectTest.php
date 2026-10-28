<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Where people land after signing in: the page they were sent away from, as a path on this site, or home. */
class AfterLoginRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_page_in_the_role_s_own_area_comes_back_as_path_and_query_only(): void
    {
        $this->assertSame('/my/bookings/new?package=graduation-portraits', UserRole::Customer->afterLogin(url('/my/bookings/new?package=graduation-portraits')));
        $this->assertSame('/my/printing/5', UserRole::Customer->afterLogin('/my/printing/5'));
        $this->assertSame('/my', UserRole::Customer->afterLogin(url('/my')));
        $this->assertSame('/owner/bookings?status=requested', UserRole::Owner->afterLogin(url('/owner/bookings?status=requested')));
    }

    public function test_the_other_role_s_area_and_public_pages_go_home(): void
    {
        $this->assertSame('/owner', UserRole::Owner->afterLogin(url('/my/bookings/new')));
        $this->assertSame('/my/dashboard', UserRole::Customer->afterLogin(url('/owner/bookings')));
        $this->assertSame('/my/dashboard', UserRole::Customer->afterLogin(url('/myself')));
        $this->assertSame('/my/dashboard', UserRole::Customer->afterLogin(url('/packages')));
        $this->assertSame('/my/dashboard', UserRole::Customer->afterLogin(null));
        $this->assertSame('/my/dashboard', UserRole::Customer->afterLogin(''));
    }

    /** @return array<string, array{string}> */
    public static function hostileAddresses(): array
    {
        return [
            'another host' => ['https://evil.example/my/dashboard'],
            'another host, same path shape' => ['http://evil.example/my/bookings/new'],
            'user info before another host' => ['http://localhost:8000@evil.example/my/bookings'],
            'user info on this host' => ['http://user:pass@localhost:8000/my/bookings'],
            'scheme-relative' => ['//evil.example/my/bookings'],
            'backslash host' => ['/\\evil.example/my/bookings'],
            'backslashes' => ['\\\\evil.example\\my'],
            'backslash inside the path' => ['/my\\..\\owner'],
            'encoded slashes' => ['/my/%2F%2Fevil.example'],
            'encoded backslash' => ['/my/%5Cevil.example'],
            'encoded dot segment' => ['/my/%2e%2e/owner'],
            'dot segment' => ['/my/../owner'],
            'double slash in the path' => ['/my//evil.example'],
            'javascript' => ['javascript:alert(1)'],
            'javascript with a path look' => ['javascript://localhost/my/%0Aalert(1)'],
            'data' => ['data:text/html,<script>alert(1)</script>'],
            'another scheme on this host' => ['ftp://localhost/my/bookings'],
            'a newline' => ["/my/bookings\r\nLocation: https://evil.example"],
            'a tab' => ["/my/\tbookings"],
            'a space' => ['/my/ bookings'],
            'triple slash' => ['///evil.example/my'],
        ];
    }

    #[DataProvider('hostileAddresses')]
    public function test_anything_unsafe_goes_home(string $intended): void
    {
        $this->assertSame('/my/dashboard', UserRole::Customer->afterLogin($intended));
    }

    public function test_signing_in_with_a_hostile_intended_address_lands_home(): void
    {
        $customer = User::factory()->create();

        $this->withSession(['url.intended' => 'http://localhost:8000@evil.example/my/bookings'])
            ->post('/login', ['email' => $customer->email, 'password' => 'password'])
            ->assertRedirect('/my/dashboard');
    }
}
