<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
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
        ], $overrides);
    }

    public function test_register_page_is_shown_to_guests(): void
    {
        $this->get('/register')->assertOk()->assertSee('Create your account');
    }

    public function test_registering_creates_a_customer_and_logs_them_in(): void
    {
        $this->post('/register', $this->validData())->assertRedirect('/my/dashboard');

        $user = User::where('email', 'maria@example.test')->firstOrFail();
        $this->assertSame(UserRole::Customer, $user->role);
        $this->assertSame('0917 123 4567', $user->phone);
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_role_field_in_the_request_has_no_effect(): void
    {
        $this->post('/register', $this->validData(['role' => 'owner']))->assertRedirect('/my/dashboard');

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
}
