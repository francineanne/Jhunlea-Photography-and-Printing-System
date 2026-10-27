<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGE = 'If that email address is registered, we have sent a password reset link to it.';

    private const INVALID = 'This password reset link is invalid or has expired. Please request a new one.';

    public function test_forgot_password_page_is_shown(): void
    {
        $this->get('/forgot-password')->assertOk()->assertSee('Forgot your password?');
    }

    public function test_a_reset_link_is_sent_to_a_registered_email(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status', self::MESSAGE);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_an_unknown_email_gets_the_same_response_and_no_notification(): void
    {
        Notification::fake();

        $this->post('/forgot-password', ['email' => 'nobody@example.test'])
            ->assertSessionHas('status', self::MESSAGE)
            ->assertSessionHasNoErrors();

        Notification::assertNothingSent();
    }

    public function test_requesting_again_right_away_gives_the_same_response(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status', self::MESSAGE);
    }

    public function test_reset_form_carries_the_token_and_email(): void
    {
        $this->get('/reset-password/abc123?email=maria@example.test')
            ->assertOk()->assertSee('abc123')->assertSee('maria@example.test');
    }

    public function test_password_can_be_reset_with_a_valid_token(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NewPassword1',
            'password_confirmation' => 'NewPassword1',
        ])->assertRedirect('/login')->assertSessionHas('status');

        $this->assertTrue(Hash::check('NewPassword1', $user->fresh()->password));
        $this->assertGuest();
    }

    public function test_other_sessions_are_signed_out_after_a_password_reset(): void
    {
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->get('/my/dashboard')->assertOk();

        // A reset done elsewhere changes the password hash this session was created with
        $user->forceFill(['password' => 'NewPassword1'])->save();

        // A real request starts with no cached user; tests share one app instance, so reset it by hand
        $this->app['auth']->forgetGuards();

        $this->get('/my/dashboard')->assertRedirect('/login');
    }

    public function test_a_token_cannot_be_used_twice(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);
        $data = ['token' => $token, 'email' => $user->email, 'password' => 'NewPassword1', 'password_confirmation' => 'NewPassword1'];

        $this->post('/reset-password', $data);
        $this->post('/reset-password', array_merge($data, ['password' => 'Another1Pass', 'password_confirmation' => 'Another1Pass']))
            ->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('NewPassword1', $user->fresh()->password));
    }

    public function test_an_invalid_token_fails_and_the_password_is_unchanged(): void
    {
        $user = User::factory()->create();

        $this->post('/reset-password', [
            'token' => 'not-a-real-token',
            'email' => $user->email,
            'password' => 'NewPassword1',
            'password_confirmation' => 'NewPassword1',
        ])->assertSessionHasErrors(['email' => self::INVALID]);

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_an_unknown_email_fails_with_the_same_message_as_a_bad_token(): void
    {
        $this->post('/reset-password', [
            'token' => 'whatever',
            'email' => 'nobody@example.test',
            'password' => 'NewPassword1',
            'password_confirmation' => 'NewPassword1',
        ])->assertSessionHasErrors(['email' => self::INVALID]);
    }

    public function test_a_weak_new_password_is_rejected(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $this->post('/reset-password', [
            'token' => $token, 'email' => $user->email, 'password' => 'weak', 'password_confirmation' => 'weak',
        ])->assertSessionHasErrors('password');
    }
}
