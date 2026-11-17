<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Phase 10C: a customer verifies their email before using any customer feature. Laravel's signed link (60 minutes),
 * the shop's own message, a resend throttle, a new email verified again, and nothing changes for walk-ins or the owner.
 * Which routes need it is RouteAccessMatrixTest's job (every customer route but the account page).
 */
class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function link(User $user, int $minutes = 60, ?string $hash = null): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addMinutes($minutes), ['id' => $user->id, 'hash' => $hash ?? sha1($user->email)]);
    }

    public function test_registering_sends_the_link_and_lands_on_check_your_email(): void
    {
        Notification::fake();

        $this->post('/register', [
            'name' => 'Maria Cruz', 'email' => 'maria@example.test', 'phone' => '0917 123 4567',
            'password' => 'Password1', 'password_confirmation' => 'Password1', 'terms' => '1',
        ])->assertRedirect(route('verification.notice'));

        $maria = User::query()->where('email', 'maria@example.test')->sole();
        $this->assertFalse($maria->hasVerifiedEmail());
        Notification::assertSentTo($maria, VerifyEmailNotification::class, function (VerifyEmailNotification $notice) use ($maria) {
            $mail = $notice->toMail($maria);

            return str_contains($mail->subject, 'Verify your email')
                && str_contains(implode(' ', $mail->introLines), 'Please confirm your email address')
                && in_array('If you did not create an account, ignore this email.', $mail->outroLines, true)
                && str_contains($mail->actionUrl, '/email/verify/'.$maria->id.'/');
        });

        $this->get(route('verification.notice'))->assertOk()->assertSee('Check your email')->assertSee('maria@example.test')
            ->assertSee('Send the link again')->assertSee('Change my email')->assertSee('Sign out');
        $this->get(route('customer.dashboard'))->assertRedirect(route('verification.notice'));
    }

    public function test_the_link_verifies_and_an_expired_or_tampered_link_is_refused(): void
    {
        $customer = User::factory()->unverified()->create();

        $this->actingAs($customer)->get($this->link($customer, -1))->assertForbidden();
        $this->actingAs($customer)->get($this->link($customer, 60, sha1('someone@else.test')))->assertForbidden();
        $this->actingAs($customer)->get(str_replace('signature=', 'signature=x', $this->link($customer)))->assertForbidden();
        $this->assertFalse($customer->fresh()->hasVerifiedEmail());

        $this->actingAs($customer)->get($this->link($customer))->assertRedirect(route('customer.dashboard'))->assertSessionHas('status', 'Your email is verified. Welcome!');
        $this->assertTrue($customer->fresh()->hasVerifiedEmail());
        $this->actingAs($customer)->get(route('customer.dashboard'))->assertOk();
        // Opening it again changes nothing; the notice page sends a verified customer home
        $this->actingAs($customer)->get($this->link($customer))->assertRedirect(route('customer.dashboard'));
        $this->actingAs($customer)->get(route('verification.notice'))->assertRedirect(route('customer.dashboard'));
        $this->assertSame(60, (int) config('auth.verification.expire', 60));
    }

    public function test_resend_is_throttled_at_three_per_ten_minutes(): void
    {
        Notification::fake();
        $customer = User::factory()->unverified()->create();

        foreach (range(1, 3) as $try) {
            $this->actingAs($customer)->from(route('verification.notice'))->post(route('verification.send'))->assertSessionHas('status');
        }
        $this->actingAs($customer)->post(route('verification.send'))->assertStatus(429);
        Notification::assertSentToTimes($customer, VerifyEmailNotification::class, 3);
    }

    public function test_a_new_email_is_verified_again_and_the_customer_stays_signed_in(): void
    {
        Notification::fake();
        $customer = User::factory()->create(['email' => 'ana@example.test', 'phone' => '09171234567']);

        $this->actingAs($customer)->post(route('customer.account.profile'), ['name' => $customer->name, 'email' => 'ana.new@example.test', 'phone' => '09171234567', 'current_password' => 'password'])
            ->assertSessionHasNoErrors();

        $customer->refresh();
        $this->assertSame(['ana.new@example.test', false], [$customer->email, $customer->hasVerifiedEmail()]);
        $this->assertAuthenticatedAs($customer);
        Notification::assertSentTo($customer, VerifyEmailNotification::class);
        $this->actingAs($customer)->get(route('customer.bookings.index'))->assertRedirect(route('verification.notice'));

        // The same email again is not a change: still verified
        $other = User::factory()->create(['email' => 'ben@example.test', 'phone' => '09181234567']);
        $this->actingAs($other)->post(route('customer.account.profile'), ['name' => 'Ben New', 'email' => 'ben@example.test', 'phone' => '09181234567'])->assertSessionHasNoErrors();
        $this->assertTrue($other->fresh()->hasVerifiedEmail());
    }

    public function test_walk_ins_are_never_emailed_and_the_owner_is_never_blocked(): void
    {
        Notification::fake();
        $walkIn = User::factory()->walkIn()->create();
        $walkIn->sendEmailVerificationNotification();
        Notification::assertNothingSent();

        $owner = User::factory()->owner()->unverified()->create();
        $this->actingAs($owner)->get(route('owner.dashboard'))->assertOk();
        $this->actingAs($owner)->get(route('owner.bookings.index'))->assertOk();
    }

    public function test_an_unverified_customer_who_signs_in_is_told_to_verify(): void
    {
        $customer = User::factory()->unverified()->create(['email' => 'late@example.test']);

        $this->post('/login', ['email' => 'late@example.test', 'password' => 'password'])
            ->assertRedirect(route('verification.notice'))
            ->assertSessionHas('status', fn ($status) => str_contains($status, 'Please verify your email first'));
        $this->assertAuthenticatedAs($customer);
    }

    public function test_the_migration_marks_every_existing_account_verified_but_not_walk_ins(): void
    {
        $customer = User::factory()->unverified()->create();
        $owner = User::factory()->owner()->unverified()->create();
        $walkIn = User::factory()->walkIn()->create();

        $migration = require database_path('migrations/2026_11_24_100000_add_accounts_verification_codes_and_avatars.php');
        (new \ReflectionMethod($migration, 'verifyExistingAccounts'))->invoke($migration);

        $this->assertTrue($customer->fresh()->hasVerifiedEmail());
        $this->assertTrue($owner->fresh()->hasVerifiedEmail());
        $this->assertNull(DB::table('users')->where('id', $walkIn->id)->value('email_verified_at'));
    }
}
