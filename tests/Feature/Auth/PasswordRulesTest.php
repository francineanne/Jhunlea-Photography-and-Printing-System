<?php

namespace Tests\Feature\Auth;

use App\Support\PasswordRules;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PasswordRulesTest extends TestCase
{
    public function test_the_server_rule_is_the_one_the_pages_describe(): void
    {
        // Password::defaults() must come from PasswordRules, so changing one place changes both
        $this->assertEquals(PasswordRules::defaults(), Password::default());
    }

    #[DataProvider('passwords')]
    public function test_the_server_accepts_and_rejects_what_the_rules_say(string $password, bool $valid): void
    {
        $passes = Validator::make(['password' => $password], ['password' => ['required', Password::defaults()]])->passes();

        $this->assertSame($valid, $passes, $password);
    }

    /** @return array<string, array{string, bool}> */
    public static function passwords(): array
    {
        return [
            'too short' => ['Abcde1', false],
            'no uppercase' => ['abcdefg1', false],
            'no lowercase' => ['ABCDEFG1', false],
            'no number' => ['Abcdefgh', false],
            'exactly the minimum' => ['Abcdefg1', true],
            'long and mixed' => ['Correct Horse 42', true],
            'accented letters count' => ['Ñandú2024x', true],
        ];
    }

    public function test_the_register_and_reset_pages_show_the_rules_from_the_single_source(): void
    {
        foreach (['/register', '/reset-password/some-token?email=a%40b.test'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            foreach (PasswordRules::all() as $rule) {
                $this->assertStringContainsString($rule['label'], $html, "{$url}: {$rule['label']}");
            }
        }

        $this->get('/register')->assertSee(PasswordRules::summary());
        $this->get('/reset-password/some-token?email=a%40b.test')->assertSee(PasswordRules::summary());
    }

    public function test_every_server_rule_has_a_matching_check_in_the_browser_script(): void
    {
        $script = file_get_contents(resource_path('js/app.js'));

        foreach (PasswordRules::all() as $rule) {
            $this->assertMatchesRegularExpression('/\b'.$rule['id'].':\s*\(value/', $script, "No browser check for the {$rule['id']} rule");
        }
    }
}
