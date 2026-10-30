<?php

namespace Tests\Feature\Account;

use App\Enums\BookingStatus;
use App\Enums\ColorScheme;
use App\Enums\UserRole;
use App\Http\Requests\Account\UpdateProfileRequest;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Phase 6C: the account page of customers (/my/account) and the owner (/owner/account). */
class AccountPageTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'password'; // the factory's

    private const NEW_PASSWORD = 'Brand-new-Pass9';

    private User $customer;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = User::factory()->create(['name' => 'Ana Reyes', 'email' => 'ana@example.test', 'phone' => '0917 123 4567']);
        $this->owner = User::factory()->owner()->create(['name' => 'Jhun Arceo', 'email' => 'owner@example.test']);
    }

    private function profile(User $user, array $data)
    {
        $area = $user->role === UserRole::Owner ? 'owner' : 'customer';

        return $this->actingAs($user)->post(route("{$area}.account.profile"), $data);
    }

    private function profileData(array $overrides = []): array
    {
        return array_replace(['name' => 'Ana Reyes', 'email' => 'ana@example.test', 'phone' => '0917 123 4567'], $overrides);
    }

    private function changePassword(array $overrides = [], ?User $user = null)
    {
        $user ??= $this->customer;
        $area = $user->role === UserRole::Owner ? 'owner' : 'customer';

        return $this->actingAs($user)->post(route("{$area}.account.password"), array_replace([
            'current_password' => self::PASSWORD, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ], $overrides));
    }

    public function test_each_role_reaches_only_its_own_page(): void
    {
        $customerRoutes = [['get', route('customer.account.show')], ['post', route('customer.account.profile')], ['post', route('customer.account.password')], ['post', route('customer.account.theme')]];
        $ownerRoutes = [['get', route('owner.account.show')], ['post', route('owner.account.profile')], ['post', route('owner.account.password')], ['post', route('owner.account.theme')]];

        $this->actingAs($this->customer)->get(route('customer.account.show'))->assertOk()->assertSee('Profile and account');
        $this->actingAs($this->owner)->get(route('owner.account.show'))->assertOk()->assertSee('Profile and account');

        foreach ($ownerRoutes as [$method, $url]) {
            $this->actingAs($this->customer)->{$method}($url, ['name' => 'Hacked', 'theme' => 'dark'])->assertForbidden();
        }
        foreach ($customerRoutes as [$method, $url]) {
            $this->actingAs($this->owner)->{$method}($url, ['name' => 'Hacked', 'theme' => 'dark'])->assertForbidden();
        }
        auth()->logout();
        foreach ([...$customerRoutes, ...$ownerRoutes] as [$method, $url]) {
            $this->{$method}($url)->assertRedirect(route('login'));
        }

        $this->assertSame('Ana Reyes', $this->customer->fresh()->name);
        $this->assertSame('Jhun Arceo', $this->owner->fresh()->name);
    }

    public function test_the_user_menu_links_to_the_right_page(): void
    {
        $this->actingAs($this->customer)->get(route('customer.dashboard'))->assertOk()->assertSee(route('customer.account.show'))->assertDontSee(route('owner.account.show'));
        $this->actingAs($this->owner)->get(route('owner.dashboard'))->assertOk()->assertSee(route('owner.account.show'))->assertDontSee(route('customer.account.show'))
            ->assertDontSee('Dashboard');
    }

    public function test_member_since_in_manila_time_and_each_customers_own_counts(): void
    {
        $this->customer->forceFill(['created_at' => Carbon::parse('2026-03-31 17:30:00', 'UTC')])->save(); // 1 April 01:30 in Manila
        $other = User::factory()->create();
        PrintingOrder::factory()->count(3)->create(['customer_id' => $this->customer->id]);
        PrintingOrder::factory()->count(5)->create(['customer_id' => $other->id]);
        PhotographyBooking::factory()->status(BookingStatus::Requested)->create(['customer_id' => $this->customer->id]);
        PhotographyBooking::factory()->count(2)->status(BookingStatus::Requested)->create(['customer_id' => $other->id]);

        $this->actingAs($this->customer)->get(route('customer.account.show'))->assertOk()
            ->assertSee('Member since')->assertSee('Apr 1, 2026')
            ->assertViewHas('counts', ['orders' => 3, 'bookings' => 1]);
        $this->actingAs($other)->get(route('customer.account.show'))->assertViewHas('counts', ['orders' => 5, 'bookings' => 2]);
        $this->actingAs($this->owner)->get(route('owner.account.show'))->assertViewHas('counts', null)->assertDontSee('Printing orders</dt>', false);
    }

    public function test_name_and_phone_change_without_the_password(): void
    {
        $this->profile($this->customer, $this->profileData(['name' => 'Ana María Reyes-Cruz', 'phone' => '+63 (917) 000-1111']))
            ->assertRedirect(route('customer.account.show').'#profile')->assertSessionHas('status', 'Profile saved.')->assertSessionHasNoErrors();

        $this->assertSame('Ana María Reyes-Cruz', $this->customer->fresh()->name);
        $this->assertSame('+63 (917) 000-1111', $this->customer->fresh()->phone);

        $this->profile($this->customer, $this->profileData(['name' => 'Ana María Reyes-Cruz', 'phone' => '']))->assertSessionHasNoErrors();
        $this->assertNull($this->customer->fresh()->phone);
    }

    public function test_a_double_submit_changes_nothing_twice(): void
    {
        $this->profile($this->customer, $this->profileData(['name' => 'Ana Bautista']));
        $stamp = $this->customer->fresh()->updated_at;
        $this->travel(5)->minutes();

        $this->profile($this->customer, $this->profileData(['name' => 'Ana Bautista']))->assertSessionHas('status', 'Nothing to change: your profile already has these details.');
        $this->assertEquals($stamp, $this->customer->fresh()->updated_at);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function badProfiles(): array
    {
        return [
            'name too short' => [['name' => 'A'], 'name'],
            'name too long' => [['name' => str_repeat('a', 81)], 'name'],
            'name control character' => [['name' => "Ana\x07"], 'name'],
            'name array' => [['name' => ['Ana']], 'name'],
            'name missing' => [['name' => ''], 'name'],
            'email invalid' => [['email' => 'not-an-email', 'current_password' => self::PASSWORD], 'email'],
            'email too long' => [['email' => str_repeat('a', 250).'@example.test', 'current_password' => self::PASSWORD], 'email'],
            'email array' => [['email' => ['ana@example.test']], 'email'],
            'phone letters' => [['phone' => 'call me'], 'phone'],
            'phone too short' => [['phone' => '123'], 'phone'],
            'phone array' => [['phone' => ['0917']], 'phone'],
        ];
    }

    #[DataProvider('badProfiles')]
    public function test_bad_profile_values_are_refused_and_nothing_changes(array $overrides, string $field): void
    {
        $this->profile($this->customer, $this->profileData($overrides))
            ->assertRedirect(route('customer.account.show').'#profile')->assertSessionHasErrorsIn('profile', $field);

        $fresh = $this->customer->fresh();
        $this->assertSame(['Ana Reyes', 'ana@example.test', '0917 123 4567'], [$fresh->name, $fresh->email, $fresh->phone]);
    }

    public function test_the_phone_rule_is_registrations_own(): void
    {
        foreach (['0917 123 4567', '+63-917-123-4567', '(02) 8123 4567'] as $phone) {
            $this->profile($this->customer, $this->profileData(['phone' => $phone]))->assertSessionHasNoErrors();
            auth()->logout();
            $this->post('/register', ['phone' => $phone])->assertSessionDoesntHaveErrors('phone');
        }
        foreach (['abc', '1234', str_repeat('1', 21)] as $phone) {
            auth()->logout();
            $this->post('/register', ['phone' => $phone])->assertSessionHasErrors(['phone' => 'Enter a valid phone number, for example 0917 123 4567.']);
            $this->profile($this->customer, $this->profileData(['phone' => $phone]))->assertSessionHasErrorsIn('profile', ['phone' => 'Enter a valid phone number, for example 0917 123 4567.']);
        }
    }

    public function test_changing_the_email_needs_the_current_password(): void
    {
        $this->profile($this->customer, $this->profileData(['email' => 'new@example.test']))
            ->assertSessionHasErrorsIn('profile', ['current_password' => 'Type your current password to change your email address.']);
        $this->profile($this->customer, $this->profileData(['email' => 'new@example.test', 'current_password' => 'Wrong-pass1']))
            ->assertSessionHasErrorsIn('profile', ['current_password' => 'That is not your current password. Nothing was changed.']);
        $this->assertSame('ana@example.test', $this->customer->fresh()->email);

        $this->profile($this->customer, $this->profileData(['email' => '  NEW@Example.TEST ', 'current_password' => self::PASSWORD, 'name' => 'Ana R.']))
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'Profile saved. Use your new email address to sign in from now on.');
        $this->assertSame('new@example.test', $this->customer->fresh()->email);
        $this->assertSame('Ana R.', $this->customer->fresh()->name);
    }

    public function test_email_is_unique_ignoring_case_but_ones_own_is_fine(): void
    {
        $this->profile($this->customer, $this->profileData(['email' => 'OWNER@example.test', 'current_password' => self::PASSWORD]))
            ->assertSessionHasErrorsIn('profile', ['email' => UpdateProfileRequest::EMAIL_TAKEN]);
        $this->assertSame('ana@example.test', $this->customer->fresh()->email);

        // A stored address in another case still counts as taken
        DB::table('users')->where('id', $this->owner->id)->update(['email' => 'Mixed@Example.test']);
        $this->profile($this->customer, $this->profileData(['email' => 'mixed@example.test', 'current_password' => self::PASSWORD]))->assertSessionHasErrorsIn('profile', 'email');

        // One's own address in another case is not a change and needs no password
        $this->profile($this->customer, $this->profileData(['email' => 'ANA@example.test']))->assertSessionHasNoErrors();
        $this->assertSame('ana@example.test', $this->customer->fresh()->email);
    }

    public function test_extra_fields_never_reach_the_account(): void
    {
        $before = $this->customer->fresh();
        $extra = [
            'role' => 'owner', 'id' => 999, 'password' => 'Hacked-pass1', 'remember_token' => 'stolen', 'email_verified_at' => null,
            'created_at' => '2000-01-01 00:00:00', 'theme' => 'dark',
        ];

        $this->profile($this->customer, $this->profileData(['name' => 'Ana Changed']) + $extra)->assertSessionHasNoErrors();
        $this->actingAs($this->customer)->post(route('customer.account.theme'), ['theme' => 'light'] + $extra);

        $after = $this->customer->fresh();
        $this->assertSame(UserRole::Customer, $after->role);
        $this->assertSame($before->id, $after->id);
        $this->assertSame($before->password, $after->password);
        $this->assertSame($before->remember_token, $after->remember_token);
        $this->assertEquals($before->email_verified_at, $after->email_verified_at);
        $this->assertEquals($before->created_at, $after->created_at);
        $this->assertSame('Ana Changed', $after->name);
        $this->assertNotContains('role', $after->getFillable());
        $this->assertNotContains('theme', $after->getFillable());
    }

    public function test_typed_html_is_stored_as_text_and_shown_escaped(): void
    {
        $this->profile($this->customer, $this->profileData(['name' => '<b>Ana</b><script>x</script>']))->assertSessionHasNoErrors();
        $this->assertSame('<b>Ana</b><script>x</script>', $this->customer->fresh()->name);

        $this->actingAs($this->customer->fresh())->get(route('customer.account.show'))->assertOk()
            ->assertDontSee('<script>x</script>', false)->assertDontSee('<b>Ana</b>', false)->assertSee('&lt;b&gt;Ana&lt;/b&gt;', false);
    }

    public function test_a_failed_section_keeps_its_input_and_leaves_the_others_alone(): void
    {
        $this->actingAs($this->customer)->post(route('customer.account.theme'), ['theme' => 'dark']);
        $this->actingAs($this->customer)->from(route('customer.account.show'))->post(route('customer.account.profile'), $this->profileData(['name' => 'Typed name', 'phone' => 'bad phone']));

        $html = $this->actingAs($this->customer)->get(route('customer.account.show'))->assertOk()
            ->assertSee('value="Typed name"', false)->assertSee('value="bad phone"', false)
            ->assertSee('Please check the fields marked below. Nothing was saved.')
            ->assertDontSee('Your password was not changed.')
            ->getContent();
        $this->assertSame('Ana Reyes', $this->customer->fresh()->name);
        $this->assertSame(ColorScheme::Dark, $this->customer->fresh()->theme);

        // A failed password change never fills a password back in and leaves the profile as it was
        $this->changePassword(['current_password' => 'Wrong-pass1']);
        $html = $this->actingAs($this->customer)->get(route('customer.account.show'))->getContent();
        $this->assertStringContainsString('Your password was not changed.', $html);
        $this->assertStringNotContainsString('Wrong-pass1', $html);
        $this->assertStringContainsString('value="Ana Reyes"', $html);
    }
}
