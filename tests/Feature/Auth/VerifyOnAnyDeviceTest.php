<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Auth\EmailVerificationController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/** Phase 10E: the verification link works on any device, and the waiting page notices by itself. */
class VerifyOnAnyDeviceTest extends TestCase
{
    use RefreshDatabase;

    private function link(User $user, int $minutes = 60, ?string $hash = null): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addMinutes($minutes), ['id' => $user->id, 'hash' => $hash ?? sha1($user->email)]);
    }

    public function test_opened_signed_out_it_verifies_and_signs_in_that_customer(): void
    {
        $maria = User::factory()->unverified()->create(['email' => 'maria@example.test']);

        $this->get($this->link($maria))->assertRedirect(route('customer.dashboard'))->assertSessionHas('status', 'Email verified. Welcome!');

        $this->assertTrue($maria->fresh()->hasVerifiedEmail());
        $this->assertAuthenticatedAs($maria);
        $this->get(route('customer.dashboard'))->assertOk();
    }

    public function test_the_intended_page_is_kept_when_signing_in_by_the_link(): void
    {
        $maria = User::factory()->unverified()->create();

        $this->withSession(['url.intended' => url('/my/bookings/new?package=family')])
            ->get($this->link($maria))->assertRedirect(url('/my/bookings/new?package=family'));
    }

    public function test_the_link_is_not_a_login_token_once_used(): void
    {
        $maria = User::factory()->unverified()->create();
        $link = $this->link($maria);

        $this->get($link)->assertRedirect(route('customer.dashboard'));
        auth()->logout();
        $this->flushSession();

        // The same link again, signed out: the account is already verified, so it asks for the password instead
        $this->get($link)->assertRedirect(route('login'))->assertSessionHas('status', 'Your email is already verified. Log in to continue.');
        $this->assertGuest();
    }

    public function test_a_different_signed_in_account_is_never_switched(): void
    {
        $ana = User::factory()->create(['email' => 'ana@example.test']);
        $maria = User::factory()->unverified()->create(['email' => 'maria.santos@example.test']);

        $this->actingAs($ana)->get($this->link($maria))->assertOk()
            ->assertSee('Email verified for')->assertSee('ma***@example.test')->assertDontSee('maria.santos@example.test')
            ->assertSee('Sign in with that account to continue.');

        $this->assertAuthenticatedAs($ana);
        $this->assertTrue($maria->fresh()->hasVerifiedEmail());
    }

    public function test_expired_tampered_and_unknown_links_get_a_friendly_page(): void
    {
        $maria = User::factory()->unverified()->create();

        $this->get($this->link($maria, -1))->assertForbidden()->assertSee('This link has expired')->assertSee('Log in to send a new link');
        $this->get(str_replace('signature=', 'signature=x', $this->link($maria)))->assertForbidden();
        $this->get($this->link($maria, 60, sha1('someone@else.test')))->assertForbidden();
        $this->get(URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => 999999, 'hash' => sha1('x')]))->assertForbidden();
        $this->assertFalse($maria->fresh()->hasVerifiedEmail());
        $this->assertGuest();

        // Signed in, the page offers to send a new link straight away
        $this->actingAs($maria)->get($this->link($maria, -1))->assertForbidden()->assertSee('Send a new link');
    }

    public function test_walk_ins_and_the_owner_are_never_signed_in_by_a_link(): void
    {
        $walkIn = User::factory()->walkIn()->create();
        $this->get(URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $walkIn->id, 'hash' => sha1('')]))->assertForbidden();

        $owner = User::factory()->owner()->create();
        $owner->forceFill(['email_verified_at' => null])->save();
        $this->get($this->link($owner))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertTrue($owner->fresh()->hasVerifiedEmail());
    }

    public function test_the_status_endpoint_answers_only_for_the_signed_in_account(): void
    {
        $this->getJson(route('verification.status'))->assertUnauthorized();

        $maria = User::factory()->unverified()->create();
        $this->actingAs($maria)->getJson(route('verification.status'))->assertOk()->assertExactJson(['verified' => false])
            ->assertHeader('Cache-Control', 'no-store, private');

        $maria->markEmailAsVerified();
        $this->actingAs($maria)->getJson(route('verification.status'))->assertExactJson(['verified' => true]);

        // The waiting page then moves on with one welcome line
        $this->actingAs($maria)->get(route('verification.notice', ['verified' => 1]))->assertRedirect(route('customer.dashboard'))
            ->assertSessionHas('status', EmailVerificationController::WELCOME);
    }

    public function test_the_status_endpoint_is_throttled(): void
    {
        $maria = User::factory()->unverified()->create();

        foreach (range(1, 30) as $i) {
            $this->actingAs($maria)->getJson(route('verification.status'))->assertOk();
        }
        $this->actingAs($maria)->getJson(route('verification.status'))->assertStatus(429);
    }

    public function test_the_waiting_page_watches_for_verification(): void
    {
        $maria = User::factory()->unverified()->create();

        $this->actingAs($maria)->get(route('verification.notice'))->assertOk()
            ->assertSee('verifyWatch', false)->assertSee('email\/verify\/status', false)
            ->assertSee('This page moves on by itself once you are verified.');
    }

    public function test_masking_shows_little_of_the_address(): void
    {
        $this->assertSame('an***@example.test', EmailVerificationController::mask('ana@example.test'));
        $this->assertSame('b***@x.test', EmailVerificationController::mask('bo@x.test'));
        $this->assertSame('a***@x.test', EmailVerificationController::mask('a@x.test'));
    }
}
