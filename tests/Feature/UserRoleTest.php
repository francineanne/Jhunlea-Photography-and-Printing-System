<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_is_cast_to_the_enum(): void
    {
        $owner = User::factory()->owner()->create()->fresh();

        $this->assertSame(UserRole::Owner, $owner->role);
        $this->assertSame('owner', $owner->getRawOriginal('role'));
    }

    public function test_new_users_are_customers_by_default(): void
    {
        $user = User::factory()->create()->fresh();

        $this->assertSame(UserRole::Customer, $user->role);
        $this->assertSame('system', $user->theme);
    }

    public function test_role_cannot_be_mass_assigned(): void
    {
        $user = User::create(['name' => 'Sneaky', 'email' => 'sneaky@example.test', 'password' => 'Password1', 'role' => 'owner'])->fresh();

        $this->assertSame(UserRole::Customer, $user->role);
    }

    public function test_each_role_has_its_home(): void
    {
        $this->assertSame('/owner', UserRole::Owner->home());
        $this->assertSame('/my/dashboard', UserRole::Customer->home());
    }
}
