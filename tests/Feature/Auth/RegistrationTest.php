<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Support\PhilippineMobile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, string> */
    private function validData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Maria Cruz',
            'email' => 'maria@example.test',
            'phone' => '0917 123 4567',
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
            'terms' => '1',
        ], $overrides);
    }

    public function test_register_page_is_shown_to_guests(): void
    {
        $this->get('/register')->assertOk()->assertSee('Create your account');
    }

    public function test_registering_creates_a_customer_and_logs_them_in(): void
    {
        // Phase 10C: a new customer is signed in and lands on "Check your email" (verification comes first)
        $this->post('/register', $this->validData())->assertRedirect(route('verification.notice'));

        $user = User::where('email', 'maria@example.test')->firstOrFail();
        $this->assertSame(UserRole::Customer, $user->role);
        // Phase 10C: stored the one way, 09XXXXXXXXX
        $this->assertSame('09171234567', $user->phone);
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_role_field_in_the_request_has_no_effect(): void
    {
        $this->post('/register', $this->validData(['role' => 'owner']))->assertRedirect(route('verification.notice'));

        $this->assertSame(UserRole::Customer, User::firstOrFail()->role);
    }

    public function test_email_is_stored_in_lowercase(): void
    {
        $this->post('/register', $this->validData(['email' => ' Maria@Example.TEST ']));

        $this->assertDatabaseHas('users', ['email' => 'maria@example.test']);
    }

    public function test_duplicate_email_is_rejected_even_with_different_case(): void
    {
        User::factory()->create(['email' => 'maria@example.test']);

        $this->post('/register', $this->validData(['email' => 'MARIA@example.test']))
            ->assertSessionHasErrors('email');

        $this->assertSame(1, User::count());
        $this->assertGuest();
    }

    public function test_invalid_input_is_rejected(): void
    {
        $this->post('/register', $this->validData(['name' => '', 'password' => 'short', 'password_confirmation' => 'different', 'phone' => 'abc']))
            ->assertSessionHasErrors(['name', 'password', 'phone']);

        $this->assertSame(0, User::count());
    }

    public function test_an_array_instead_of_an_email_is_a_validation_error_not_a_crash(): void
    {
        $this->post('/register', $this->validData(['email' => ['a@example.test']]))->assertSessionHasErrors('email');
    }

    public function test_password_must_have_mixed_case_and_a_number(): void
    {
        $this->post('/register', $this->validData(['password' => 'alllowercase1', 'password_confirmation' => 'alllowercase1']))
            ->assertSessionHasErrors('password');
    }

    public function test_the_session_id_changes_after_registering(): void
    {
        $this->startSession();
        $before = session()->getId();

        $this->post('/register', $this->validData());

        $this->assertNotSame($before, session()->getId());
    }
    // ---- Phase 10C: required Philippine mobile number, consent, kept values

    public function test_every_common_way_of_typing_a_mobile_number_is_stored_the_same(): void
    {
        foreach (['0917 123 4567', '09171234567', '+63 917 123 4567', '63-917-123-4567', ' (0917) 123.4567 '] as $n => $typed) {
            $this->post('/register', $this->validData(['email' => "p{$n}@example.test", 'phone' => $typed]))->assertSessionHasNoErrors();
            $this->assertSame('09171234567', User::where('email', "p{$n}@example.test")->value('phone'), $typed);
            auth()->logout();
        }
    }

    public function test_a_missing_or_non_mobile_number_is_refused(): void
    {
        $this->post('/register', $this->validData(['phone' => '']))->assertSessionHasErrors(['phone' => 'Enter your mobile number, for example 0917 123 4567.']);
        foreach (['(02) 8123 4567', '0817 123 4567', '+1 415 555 0100', '0917 123 456', '0917 123 45678', '09171234567 ext 2'] as $bad) {
            $this->post('/register', $this->validData(['phone' => $bad]))->assertSessionHasErrors(['phone' => PhilippineMobile::MESSAGE]);
        }
        $this->assertSame(0, User::count());
    }

    public function test_consent_is_required(): void
    {
        foreach ([null, '0', ''] as $terms) {
            $data = $this->validData();
            unset($data['terms']);
            if ($terms !== null) {
                $data['terms'] = $terms;
            }
            $this->post('/register', $data)->assertSessionHasErrors(['terms' => RegisterRequest::TERMS_MESSAGE]);
        }
        $this->assertSame(0, User::count());
    }

    public function test_a_refused_form_keeps_what_was_typed_but_never_the_password(): void
    {
        $this->from('/register')->post('/register', $this->validData(['phone' => 'abc']))->assertRedirect('/register');

        $this->get('/register')->assertOk()
            ->assertSee('value="Maria Cruz"', false)->assertSee('value="maria@example.test"', false)->assertSee('value="abc"', false)
            ->assertDontSee('Password1')->assertSee(PhilippineMobile::MESSAGE);
    }

    public function test_the_register_page_shows_the_consent_box_and_the_rules(): void
    {
        $this->get('/register')->assertOk()
            ->assertSee('name="terms"', false)->assertSee('Data Privacy Act of 2012 (RA 10173)')->assertSee(route('terms'), false)
            ->assertSee('autocomplete="tel"', false)->assertSee('autocomplete="new-password"', false)->assertSee('Show password', false);
    }

    public function test_the_terms_page_says_what_is_collected_who_sees_it_and_how_to_ask(): void
    {
        $this->get('/terms')->assertOk()
            ->assertSee('Terms and privacy')->assertSee('What we collect and why')->assertSee('Who sees it')
            ->assertSee('How long we keep it')->assertSee('Your rights and how to ask')->assertSee('RA 10173');
        $this->get('/')->assertSee(route('terms'), false);
    }
}
