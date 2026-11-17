<?php

namespace Tests\Feature\Auth;

use App\Models\PasswordResetCode;
use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use App\Notifications\PasswordResetCodeNotification;
use App\Services\PasswordResetCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Password reset. Since Phase 10C a one-time 6-digit code replaces the reset link: the same answer for every email, the
 * code kept only as a hash, 10 minutes, 5 tries, one active code, used once; the cases of the link era are kept and
 * now go through the code.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGE = PasswordResetCodeService::SENT_MESSAGE;

    private const INVALID = PasswordResetCodeService::INVALID_MESSAGE;

    /** Asks for a code and returns the one that was emailed. */
    private function codeFor(User $user): string
    {
        Notification::fake();
        $this->post('/forgot-password', ['email' => $user->email]);
        $code = null;
        Notification::assertSentTo($user, PasswordResetCodeNotification::class, function (PasswordResetCodeNotification $notice) use (&$code) {
            $code = $notice->code();

            return true;
        });

        return $code;
    }

    private function reset(string $email, string $code, string $password = 'NewPassword1')
    {
        return $this->post('/reset-password', ['email' => $email, 'code' => $code, 'password' => $password, 'password_confirmation' => $password]);
    }

    public function test_forgot_password_page_is_shown(): void
    {
        $this->get('/forgot-password')->assertOk()->assertSee('Forgot your password?')->assertSee('6-digit code');
    }

    public function test_a_code_is_sent_to_a_registered_email_and_kept_only_as_a_hash(): void
    {
        $user = User::factory()->create();
        Notification::fake();

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertRedirect(route('password.reset', ['email' => $user->email]))->assertSessionHas('status', self::MESSAGE);

        Notification::assertSentTo($user, PasswordResetCodeNotification::class, function (PasswordResetCodeNotification $notice) use ($user) {
            $row = PasswordResetCode::query()->where('user_id', $user->id)->sole();
            $mail = $notice->toMail($user);

            return preg_match('/^\d{6}$/', $notice->code()) === 1
                && $row->code_hash !== $notice->code() && Hash::check($notice->code(), $row->code_hash)
                && abs($row->expires_at->diffInSeconds(now()->addMinutes(10))) < 2 && str_contains(implode(' ', $mail->introLines), $notice->code());
        });
    }

    public function test_an_unknown_email_and_a_walk_in_get_the_same_response_and_no_email(): void
    {
        Notification::fake();
        $walkIn = User::factory()->create(['email' => 'odd@example.test']);
        $walkIn->forceFill(['is_walk_in' => true])->save();

        foreach (['nobody@example.test', 'odd@example.test'] as $email) {
            $this->post('/forgot-password', ['email' => $email])
                ->assertRedirect(route('password.reset', ['email' => $email]))->assertSessionHas('status', self::MESSAGE);
        }

        Notification::assertNothingSent();
        $this->assertSame(0, PasswordResetCode::count());
    }

    public function test_requesting_again_replaces_the_code(): void
    {
        $user = User::factory()->create();
        $first = $this->codeFor($user);
        $second = $this->codeFor($user);

        $this->assertSame(1, PasswordResetCode::query()->where('user_id', $user->id)->count());
        if ($first !== $second) {
            $this->reset($user->email, $first)->assertSessionHasErrors(['code' => self::INVALID]);
        }
        $this->reset($user->email, $second)->assertRedirect('/login');
    }

    public function test_reset_form_carries_the_email(): void
    {
        $this->get('/reset-password?email=maria@example.test')
            ->assertOk()->assertSee('maria@example.test')->assertSee('6-digit code')->assertSee('autocomplete="one-time-code"', false);
    }

    public function test_an_old_reset_link_leads_back_to_forgot_password(): void
    {
        $this->get('/reset-password/abc123?email=maria@example.test')->assertRedirect(route('password.request'))->assertSessionHas('status');
    }

    public function test_password_can_be_reset_with_a_valid_code_and_every_session_is_signed_out(): void
    {
        $user = User::factory()->create();
        DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => time()]);
        $code = $this->codeFor($user);

        $this->reset($user->email, $code)->assertRedirect('/login')->assertSessionHas('status');

        $this->assertTrue(Hash::check('NewPassword1', $user->fresh()->password));
        $this->assertGuest();
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertSame(0, PasswordResetCode::count());
        Notification::assertSentTo($user, PasswordChangedNotification::class);
        $this->post('/login', ['email' => $user->email, 'password' => 'NewPassword1'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
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

    public function test_a_code_cannot_be_used_twice(): void
    {
        $user = User::factory()->create();
        $code = $this->codeFor($user);

        $this->reset($user->email, $code);
        $this->reset($user->email, $code, 'Another1Pass')->assertSessionHasErrors(['code' => self::INVALID]);

        $this->assertTrue(Hash::check('NewPassword1', $user->fresh()->password));
    }

    public function test_an_invalid_code_fails_and_five_wrong_tries_burn_the_code(): void
    {
        $user = User::factory()->create();
        $code = $this->codeFor($user);
        $wrong = $code === '000000' ? '111111' : '000000';

        foreach (range(1, 4) as $try) {
            $this->reset($user->email, $wrong)->assertSessionHasErrors(['code' => self::INVALID]);
        }
        $this->assertSame(4, PasswordResetCode::query()->sole()->attempts);

        $this->reset($user->email, $wrong)->assertSessionHasErrors(['code' => self::INVALID]);
        $this->assertSame(0, PasswordResetCode::count(), 'The fifth wrong try burns the code');
        $this->reset($user->email, $code)->assertSessionHasErrors(['code' => self::INVALID]);

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_an_expired_code_is_refused_and_deleted(): void
    {
        $user = User::factory()->create();
        $code = $this->codeFor($user);
        $this->travel(11)->minutes();

        $this->reset($user->email, $code)->assertSessionHasErrors(['code' => self::INVALID]);
        $this->assertSame(0, PasswordResetCode::count());
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_an_unknown_email_fails_with_the_same_message_as_a_bad_code(): void
    {
        $this->reset('nobody@example.test', '123456')->assertSessionHasErrors(['code' => self::INVALID]);
    }

    public function test_a_weak_new_password_is_rejected(): void
    {
        $user = User::factory()->create();
        $code = $this->codeFor($user);

        $this->reset($user->email, $code, 'weak')->assertSessionHasErrors('password');
        $this->assertSame(0, PasswordResetCode::query()->sole()->attempts, 'A refused form is not a wrong try');
    }

    public function test_requests_and_tries_are_limited_per_email(): void
    {
        $user = User::factory()->create();
        Notification::fake();

        foreach (range(1, 3) as $try) {
            $this->post('/forgot-password', ['email' => $user->email])->assertRedirect();
        }
        $this->post('/forgot-password', ['email' => $user->email])->assertTooManyRequests();
        $this->post('/forgot-password', ['email' => 'other@example.test'])->assertRedirect();

        $this->travel(11)->minutes();
        foreach (range(1, 10) as $try) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.'.$try])->post('/reset-password', ['email' => $user->email, 'code' => '123456', 'password' => 'NewPassword1', 'password_confirmation' => 'NewPassword1']);
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.1'])->post('/reset-password', ['email' => $user->email, 'code' => '123456', 'password' => 'NewPassword1', 'password_confirmation' => 'NewPassword1'])
            ->assertTooManyRequests();
    }
}
