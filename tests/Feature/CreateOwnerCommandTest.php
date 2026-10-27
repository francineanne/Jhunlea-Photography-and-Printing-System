<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateOwnerCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_owner(): void
    {
        $this->artisan('jhunlea:create-owner')
            ->expectsQuestion('Name', 'Shop Owner')
            ->expectsQuestion('Email', 'Owner@Example.test')
            ->expectsQuestion('Password', 'Password1')
            ->expectsQuestion('Confirm password', 'Password1')
            ->assertSuccessful();

        $owner = User::where('email', 'owner@example.test')->firstOrFail();
        $this->assertSame(UserRole::Owner, $owner->role);
        $this->assertTrue(Hash::check('Password1', $owner->password));
    }

    // In a real terminal the prompt asks again after a validation error; without a terminal it stops with a failure
    public function test_it_refuses_an_existing_email(): void
    {
        User::factory()->create(['email' => 'taken@example.test']);

        $this->artisan('jhunlea:create-owner')
            ->expectsQuestion('Name', 'Shop Owner')
            ->expectsQuestion('Email', 'TAKEN@example.test')
            ->assertFailed();

        $this->assertSame(1, User::count());
        $this->assertSame(0, User::where('role', UserRole::Owner)->count());
    }

    public function test_it_rejects_a_weak_password(): void
    {
        $this->artisan('jhunlea:create-owner')
            ->expectsQuestion('Name', 'Shop Owner')
            ->expectsQuestion('Email', 'owner@example.test')
            ->expectsQuestion('Password', 'weak')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_it_rejects_a_password_confirmation_that_does_not_match(): void
    {
        $this->artisan('jhunlea:create-owner')
            ->expectsQuestion('Name', 'Shop Owner')
            ->expectsQuestion('Email', 'owner@example.test')
            ->expectsQuestion('Password', 'Password1')
            ->expectsQuestion('Confirm password', 'Different1')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_it_asks_for_confirmation_when_an_owner_already_exists(): void
    {
        User::factory()->owner()->create();

        $this->artisan('jhunlea:create-owner')
            ->expectsConfirmation('Do you really want to create another owner account?', 'no')
            ->assertFailed();

        $this->assertSame(1, User::where('role', UserRole::Owner)->count());
    }

    public function test_it_creates_a_second_owner_after_confirmation(): void
    {
        User::factory()->owner()->create();

        $this->artisan('jhunlea:create-owner')
            ->expectsConfirmation('Do you really want to create another owner account?', 'yes')
            ->expectsQuestion('Name', 'Second Owner')
            ->expectsQuestion('Email', 'second@example.test')
            ->expectsQuestion('Password', 'Password1')
            ->expectsQuestion('Confirm password', 'Password1')
            ->assertSuccessful();

        $this->assertSame(2, User::where('role', UserRole::Owner)->count());
    }
}
