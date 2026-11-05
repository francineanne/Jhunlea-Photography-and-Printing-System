<?php

namespace Tests\Feature\Assistant;

use Tests\TestCase;

/**
 * The Claude key and model are read ONLY from AI_ANTHROPIC_API_KEY and AI_ANTHROPIC_MODEL (Phase 7E-2a). The plain
 * ANTHROPIC_API_KEY is used by other tools and can sit in the process environment, where it would shadow .env, so it is
 * ignored. The values here are dummies, never a key.
 */
class AnthropicKeyNameTest extends TestCase
{
    private const NAMES = ['AI_ANTHROPIC_API_KEY', 'AI_ANTHROPIC_MODEL', 'ANTHROPIC_API_KEY', 'ANTHROPIC_MODEL'];

    /** @var array<string, array{server: mixed, env: mixed, putenv: string|false}> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::NAMES as $name) {
            $this->saved[$name] = ['server' => $_SERVER[$name] ?? null, 'env' => $_ENV[$name] ?? null, 'putenv' => getenv($name)];
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            $this->setVariable($name, $value['server']);
            $value['putenv'] === false ? putenv($name) : putenv("{$name}={$value['putenv']}");
        }

        parent::tearDown();
    }

    private function setVariable(string $name, ?string $value): void
    {
        if ($value === null) {
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);

            return;
        }

        $_SERVER[$name] = $_ENV[$name] = $value;
        putenv("{$name}={$value}");
    }

    /** @return array<string, mixed> the anthropic section of a freshly evaluated config/assistant.php */
    private function freshConfig(): array
    {
        return (require config_path('assistant.php'))['anthropic'];
    }

    public function test_the_new_names_are_read(): void
    {
        $this->setVariable('AI_ANTHROPIC_API_KEY', 'dummy-new-name');
        $this->setVariable('AI_ANTHROPIC_MODEL', 'dummy-model');
        $this->setVariable('ANTHROPIC_API_KEY', null);
        $this->setVariable('ANTHROPIC_MODEL', null);

        $config = $this->freshConfig();

        $this->assertSame('dummy-new-name', $config['api_key']);
        $this->assertSame('dummy-model', $config['model']);
    }

    public function test_the_old_names_are_ignored_even_in_the_process_environment(): void
    {
        $this->setVariable('AI_ANTHROPIC_API_KEY', null);
        $this->setVariable('AI_ANTHROPIC_MODEL', null);
        $this->setVariable('ANTHROPIC_API_KEY', 'dummy-old-name');
        $this->setVariable('ANTHROPIC_MODEL', 'old-model');

        $config = $this->freshConfig();

        $this->assertNull($config['api_key']);
        $this->assertSame('claude-haiku-5-5', $config['model']);
    }

    public function test_the_new_name_wins_when_both_are_set(): void
    {
        $this->setVariable('AI_ANTHROPIC_API_KEY', 'dummy-new-name');
        $this->setVariable('ANTHROPIC_API_KEY', 'dummy-old-name');

        $this->assertSame('dummy-new-name', $this->freshConfig()['api_key']);
    }

    public function test_no_file_names_the_old_variables_and_the_example_has_no_value(): void
    {
        $config = file_get_contents(config_path('assistant.php'));
        $this->assertDoesNotMatchRegularExpression("/env\\('ANTHROPIC_/", $config);

        $example = file_get_contents(base_path('.env.example'));
        $this->assertMatchesRegularExpression('/^AI_ANTHROPIC_API_KEY=\s*$/m', $example);
        $this->assertMatchesRegularExpression('/^AI_ANTHROPIC_MODEL=\s*$/m', $example);
        $this->assertDoesNotMatchRegularExpression('/^ANTHROPIC_API_KEY=/m', $example);
    }
}
