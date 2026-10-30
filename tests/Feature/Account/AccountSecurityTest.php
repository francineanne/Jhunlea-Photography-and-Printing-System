<?php

namespace Tests\Feature\Account;

use App\Enums\ColorScheme;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Phase 6C: changing the password (other devices signed out) and the appearance stored on the account. */
class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'password'; // the factory's

    private const NEW_PASSWORD = 'Brand-new-Pass9';

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = User::factory()->create(['email' => 'ana@example.test']);
    }

    private function change(array $overrides = [], ?User $user = null)
    {
        $user ??= $this->customer;
        $area = $user->role->value === 'owner' ? 'owner' : 'customer';

        return $this->actingAs($user)->post(route("{$area}.account.password"), array_replace([
            'current_password' => self::PASSWORD, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ], $overrides));
    }

    private function sessionRow(string $id, ?int $userId): void
    {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'payload' => 'x', 'last_activity' => time()]);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function refused(): array
    {
        return [
            'wrong current password' => [['current_password' => 'Wrong-pass1'], 'current_password'],
            'missing current password' => [['current_password' => ''], 'current_password'],
            'same as the current one' => [['current_password' => 'Same-pass99', 'password' => 'Same-pass99', 'password_confirmation' => 'Same-pass99'], 'password'],
            'too short' => [['password' => 'Ab1defg', 'password_confirmation' => 'Ab1defg'], 'password'],
            'no upper case' => [['password' => 'lowercase9', 'password_confirmation' => 'lowercase9'], 'password'],
            'no lower case' => [['password' => 'UPPERCASE9', 'password_confirmation' => 'UPPERCASE9'], 'password'],
            'no number' => [['password' => 'NoNumbersHere', 'password_confirmation' => 'NoNumbersHere'], 'password'],
            'confirmation differs' => [['password_confirmation' => 'Other-pass99'], 'password'],
            'array password' => [['password' => ['Brand-new-Pass9'], 'password_confirmation' => ['Brand-new-Pass9']], 'password'],
            'array current password' => [['current_password' => ['password']], 'current_password'],
        ];
    }

    #[DataProvider('refused')]
    public function test_a_refused_change_keeps_the_old_password(array $overrides, string $field): void
    {
        if (($overrides['current_password'] ?? null) === 'Same-pass99') {
            $this->customer->forceFill(['password' => 'Same-pass99'])->save();
        }
        $hash = $this->customer->fresh()->password;

        $this->change($overrides)->assertRedirect(route('customer.account.show').'#security')->assertSessionHasErrorsIn('password', $field);

        $this->assertSame($hash, $this->customer->fresh()->password);
    }

    public function test_the_password_rules_are_the_registration_rules(): void
    {
        foreach (['Ab1defg', 'lowercase9', 'UPPERCASE9', 'NoNumbersHere'] as $weak) {
            $this->change(['password' => $weak, 'password_confirmation' => $weak])->assertSessionHasErrorsIn('password', 'password');
            auth()->logout();
            $this->post('/register', ['name' => 'X', 'email' => 'x'.strtolower($weak).'@example.test', 'password' => $weak, 'password_confirmation' => $weak])->assertSessionHasErrors('password');
        }
    }

    public function test_a_change_signs_out_other_devices_only(): void
    {
        $other = User::factory()->create();
        $this->sessionRow('this-user-phone', $this->customer->id);
        $this->sessionRow('this-user-laptop', $this->customer->id);
        $this->sessionRow('someone-else', $other->id);
        $this->sessionRow('a-guest', null);
        $oldToken = $this->customer->fresh()->remember_token;

        $this->actingAs($this->customer)->get(route('customer.account.show'));
        $before = session()->getId();

        $this->change()->assertRedirect(route('customer.account.show').'#security')
            ->assertSessionHas('status', 'Password changed. You stay signed in here; every other device where you were signed in has been signed out.');

        $this->assertNotSame($before, session()->getId());
        $this->assertSame(['a-guest', 'someone-else'], DB::table('sessions')->orderBy('id')->pluck('id')->all());
        $this->assertNotSame($oldToken, $this->customer->fresh()->remember_token);
        $this->assertAuthenticatedAs($this->customer);
    }

    public function test_the_new_password_signs_in_and_the_old_one_no_longer_does(): void
    {
        $this->change()->assertSessionHasNoErrors();

        $hash = $this->customer->fresh()->password;
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $hash));
        $this->assertNotSame(self::NEW_PASSWORD, $hash);

        auth()->logout();
        $this->post('/login', ['email' => 'ana@example.test', 'password' => self::PASSWORD])->assertSessionHasErrors();
        $this->assertGuest();
        $this->post('/login', ['email' => 'ana@example.test', 'password' => self::NEW_PASSWORD])->assertRedirect();
        $this->assertAuthenticatedAs($this->customer);
    }

    public function test_no_password_or_hash_ever_appears(): void
    {
        $oldHash = $this->customer->fresh()->password;
        $response = $this->change();
        $newHash = $this->customer->fresh()->password;

        $flash = json_encode(session()->all());
        foreach ([self::PASSWORD.'"', self::NEW_PASSWORD, $oldHash, $newHash] as $secret) {
            $this->assertStringNotContainsString($secret, $flash);
            $this->assertStringNotContainsString($secret, (string) $response->getContent());
        }

        $html = $this->actingAs($this->customer->fresh())->get(route('customer.account.show'))->getContent();
        foreach ([self::NEW_PASSWORD, $newHash] as $secret) {
            $this->assertStringNotContainsString($secret, $html);
        }

        // A refused change does not flash what was typed either
        $this->change(['current_password' => 'Typed-wrong7', 'password' => 'Typed-new88', 'password_confirmation' => 'Typed-new88']);
        $this->assertStringNotContainsString('Typed-new88', json_encode(session()->all()));
        $this->assertStringNotContainsString('Typed-wrong7', json_encode(session()->all()));
    }

    public function test_the_owner_changes_their_own_password_here(): void
    {
        $owner = User::factory()->owner()->create();

        $this->change([], $owner)->assertRedirect(route('owner.account.show').'#security')->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $owner->fresh()->password));
    }

    public function test_only_the_three_themes_are_accepted(): void
    {
        foreach (['blue', 'DARK', '', 'sys tem', '1'] as $bad) {
            $this->actingAs($this->customer)->post(route('customer.account.theme'), ['theme' => $bad])->assertSessionHasErrorsIn('theme', 'theme');
        }
        $this->actingAs($this->customer)->post(route('customer.account.theme'), ['theme' => ['dark']])->assertSessionHasErrorsIn('theme', 'theme');
        $this->actingAs($this->customer)->postJson(route('customer.account.theme'), ['theme' => 'purple'])->assertUnprocessable();
        $this->assertSame(ColorScheme::System, $this->customer->fresh()->theme);

        foreach (ColorScheme::cases() as $theme) {
            $this->actingAs($this->customer)->post(route('customer.account.theme'), ['theme' => $theme->value])
                ->assertRedirect(route('customer.account.show').'#appearance')->assertSessionHasNoErrors();
            $this->assertSame($theme, $this->customer->fresh()->theme);
        }
    }

    public function test_the_saved_theme_is_rendered_by_the_server_on_the_next_page(): void
    {
        $this->actingAs($this->customer)->post(route('customer.account.theme'), ['theme' => 'dark']);
        $html = $this->actingAs($this->customer->fresh())->get(route('customer.dashboard'))->getContent();
        $this->assertMatchesRegularExpression('/<html[^>]*data-theme="dark"[^>]*class="dark"/', $html);
        $this->assertStringContainsString('<meta name="theme-save-url" content="'.route('customer.account.theme').'">', $html);

        $this->actingAs($this->customer)->post(route('customer.account.theme'), ['theme' => 'light']);
        $html = $this->actingAs($this->customer->fresh())->get(route('customer.account.show'))->getContent();
        $this->assertMatchesRegularExpression('/<html[^>]*data-theme="light"/', $html);
        $this->assertDoesNotMatchRegularExpression('/<html[^>]*class="dark"/', $html);

        // Guests keep the choice in their browser: no saved value and nowhere to save it
        auth()->logout();
        $html = $this->get(route('home'))->getContent();
        $this->assertMatchesRegularExpression('/<html[^>]*data-theme="guest"/', $html);
        $this->assertStringNotContainsString('theme-save-url', $html);
    }

    public function test_the_header_button_and_the_account_page_agree(): void
    {
        // The header button saves in the background (JSON); the account page then shows that choice
        $this->actingAs($this->customer)->postJson(route('customer.account.theme'), ['theme' => 'dark'])->assertOk()->assertExactJson(['theme' => 'dark']);
        $this->assertSame(ColorScheme::Dark, $this->customer->fresh()->theme);

        $html = $this->actingAs($this->customer->fresh())->get(route('customer.account.show'))->getContent();
        $this->assertMatchesRegularExpression('/<input type="radio" name="theme" value="dark" class="sr-only" checked/', $html);
        $this->assertStringContainsString('data-theme-toggle', $html);

        $owner = User::factory()->owner()->create();
        $html = $this->actingAs($owner)->get(route('owner.dashboard'))->getContent();
        $this->assertStringContainsString('<meta name="theme-save-url" content="'.route('owner.account.theme').'">', $html);
    }
}
