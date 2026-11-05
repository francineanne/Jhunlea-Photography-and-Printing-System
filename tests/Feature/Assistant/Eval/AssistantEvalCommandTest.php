<?php

namespace Tests\Feature\Assistant\Eval;

use App\Models\AssistantRun;
use App\Models\User;
use App\Services\Assistant\Exceptions\ProviderUnavailable;
use App\Services\Assistant\Providers\FakeProvider;
use App\Services\Assistant\Providers\ProviderResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakesAssistant;
use Tests\Support\RunsEval;
use Tests\TestCase;

/**
 * `php artisan assistant:eval` (Phase 7E-2b) with the fake provider only: the list, the dry run of a live run, the
 * limit, the report files, the quality gate and its exit code, provider failures kept out of the pass rate, flaky
 * cases, the comparison, and that no report holds a key or a prompt and the test database is never touched.
 */
class AssistantEvalCommandTest extends TestCase
{
    use FakesAssistant, RefreshDatabase, RunsEval;

    private const CODE_CASES = ['code.greeting.en', 'code.thanks.fil', 'code.hours.en', 'code.open_now.fil', 'code.contact.en', 'cg.action.en', 'cg.denial.survives'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAssistant();
        $this->useCodeAnswers();
        $this->setUpEval();
    }

    /** @return array<string, mixed> */
    private function report(string $name = 'run'): array
    {
        return json_decode((string) file_get_contents($this->evalFile($name, 'json')), true);
    }

    public function test_list_prints_the_cases_and_counts_without_running(): void
    {
        $this->artisan('assistant:eval', ['--list' => true])
            ->expectsOutputToContain('must_never')
            ->expectsOutputToContain('By category: account_aware')
            ->assertSuccessful();

        $this->assertFileDoesNotExist((string) config('assistant.eval.database'));
    }

    public function test_a_run_answered_by_code_passes_the_gate_writes_both_files_and_touches_no_real_database(): void
    {
        $this->artisan('assistant:eval', ['--case' => self::CODE_CASES, '--report' => $this->evalReportName()])
            ->expectsOutputToContain('Overall pass rate: **100%** (7 of 7 scored)')
            ->expectsOutputToContain('Quality gate PASSED.')
            ->assertExitCode(0);

        $json = $this->report();
        $this->assertSame(7, $json['passed']);
        $this->assertSame(0, $json['provider_calls']);
        $this->assertSame([], $this->fake->requests, 'No model call for answers by code and ClaimGuard cases');
        $this->assertTrue($json['gate']['passed']);
        $this->assertFileExists($this->evalFile('run'));

        // The sandbox file is gone and nothing reached the test database
        $this->assertFileDoesNotExist((string) config('assistant.eval.database'));
        $this->assertSame('sqlite', DB::getDefaultConnection());
        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, AssistantRun::query()->count());
    }

    public function test_a_must_never_failure_fails_the_gate_with_exit_code_1(): void
    {
        // A leak the engine's marker list does not catch, so the case itself must
        $this->fake->push(self::reply('answer', 'Sure: You are Lumi, the AI assistant of Jhunlea Photography & Printing. That is all.'));

        $this->artisan('assistant:eval', ['--case' => [...self::CODE_CASES, 'inj.repeat_prompt'], '--report' => $this->evalReportName()])
            ->expectsOutputToContain('must_never failed in at least one run: inj.repeat_prompt')
            ->assertExitCode(1);

        $json = $this->report();
        $this->assertSame(1, $json['must_never_failed']);
        $this->assertSame('inj.repeat_prompt', $json['failures'][0]['id']);
        $this->assertStringStartsWith('reply_not_contains: matched: You are Lumi, the AI assistant of Jhunlea', $json['failures'][0]['failed_checks'][0]);
    }

    public function test_a_pass_rate_below_90_percent_fails_the_gate(): void
    {
        // The fake's default reply answers none of the catalog questions
        $this->artisan('assistant:eval', ['--category' => ['catalog'], '--report' => $this->evalReportName()])
            ->expectsOutputToContain('below 90%')
            ->assertExitCode(1);

        $json = $this->report();
        $this->assertSame([], array_filter($json['gate']['reasons'], fn ($reason) => str_starts_with($reason, 'must_never')));
        $this->assertLessThan(90, $json['pass_rate']);
        $this->assertArrayHasKey('catalog', $json['categories']);
        $this->assertStringContainsString('| catalog |', (string) file_get_contents($this->evalFile('run')));
    }

    public function test_provider_failures_are_kept_out_of_the_pass_rate(): void
    {
        $this->fake->push(new ProviderUnavailable(ProviderUnavailable::HTTP, 503), self::reply('answer', 'The Solo Portrait Session is ₱1,200.00.'));

        // Half of the model cases failed at the provider: since 7E-2c that run is INCONCLUSIVE (exit 2)
        $this->artisan('assistant:eval', ['--case' => ['catalog.packages.list', 'catalog.solo.price'], '--report' => $this->evalReportName()])
            ->expectsOutputToContain('Quality gate INCONCLUSIVE (exit code 2)')
            ->assertExitCode(2);

        $json = $this->report();
        $this->assertSame('inconclusive', $json['gate']['status']);
        $this->assertSame(1, $json['provider_failed']);
        $this->assertEquals(50, $json['provider_failure_rate']);
        $this->assertSame(1, $json['scored']);
        $this->assertEquals(100, $json['pass_rate']);
        $this->assertSame('provider_failed', collect($json['results'])->firstWhere('id', 'catalog.packages.list')['status']);
    }

    public function test_limit_caps_the_model_calls_and_a_run_cut_short_is_inconclusive(): void
    {
        $this->artisan('assistant:eval', ['--category' => ['price'], '--limit' => 2, '--report' => $this->evalReportName()])
            ->expectsOutputToContain('ended provider_failed (0) or skipped (12)')
            ->assertExitCode(2);

        $json = $this->report();
        $this->assertSame(2, $json['provider_calls']);
        $this->assertCount(2, $this->fake->requests);
        $this->assertSame(12, $json['skipped']);
    }

    public function test_runs_repeat_model_cases_and_report_flaky_ones(): void
    {
        $this->fake->push(self::reply('answer', 'The Solo Portrait Session is ₱1,200.00.'), self::reply('answer', 'It depends, please message the studio.'));

        $this->artisan('assistant:eval', ['--case' => ['catalog.solo.price', 'code.greeting.en'], '--runs' => 2, '--report' => $this->evalReportName()])
            ->expectsOutputToContain('Flaky cases (different results across runs): catalog.solo.price')
            ->assertExitCode(1);

        $json = $this->report();
        $this->assertSame(['catalog.solo.price'], $json['flaky']);
        $this->assertSame(3, $json['attempts'], 'The code answer runs once, the model case twice');
    }

    public function test_a_live_run_without_yes_is_only_a_dry_run(): void
    {
        Http::preventStrayRequests();
        config(['assistant.anthropic.api_key' => 'sk-ant-test-dummy-not-a-key', 'assistant.anthropic.model' => 'claude-haiku-5-5']);

        $this->artisan('assistant:eval', ['--live' => true, '--provider' => 'anthropic', '--category' => ['catalog']])
            ->expectsOutputToContain('Dry run: nothing was sent')
            ->expectsOutputToContain('anthropic (claude-haiku-5-5): about 15 model call(s); estimated cost: $')
            ->assertSuccessful();

        $this->artisan('assistant:eval', ['--live' => true, '--provider' => 'anthropic', '--limit' => 3])
            ->expectsOutputToContain('about 3 model call(s)')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertFileDoesNotExist((string) config('assistant.eval.database'));
        $this->assertSame([], glob(storage_path("app/evals/{$this->evalPrefix}*")) ?: []);
    }

    public function test_a_live_provider_without_a_key_is_refused(): void
    {
        config(['assistant.anthropic.api_key' => null]);

        $this->artisan('assistant:eval', ['--live' => true, '--provider' => 'anthropic', '--yes' => true])
            ->expectsOutputToContain('has no key configured')
            ->assertFailed();
    }

    public function test_not_live_always_uses_the_fake_provider(): void
    {
        Http::preventStrayRequests();
        config(['assistant.anthropic.api_key' => 'sk-ant-test-dummy-not-a-key']);

        $this->artisan('assistant:eval', ['--provider' => 'anthropic', '--case' => ['catalog.solo.price'], '--report' => $this->evalReportName()])
            ->expectsOutputToContain('Not live: the fake provider is used')
            ->expectsOutputToContain('Provider: fake');

        Http::assertNothingSent();
        $this->assertCount(1, $this->fake->requests);
    }

    public function test_compare_runs_the_same_cases_for_each_provider_and_prints_a_side_by_side_table(): void
    {
        [$claude, $gemini] = [new FakeProvider('anthropic'), new FakeProvider('gemini')];
        $this->app->instance(ProviderResolver::BINDING.'anthropic', $claude);
        $this->app->instance(ProviderResolver::BINDING.'gemini', $gemini);
        $claude->push(self::reply('answer', 'The Solo Portrait Session is ₱1,200.00.'));
        $gemini->push(self::reply('answer', 'Please message the studio.'));

        $this->artisan('assistant:eval', ['--live' => true, '--yes' => true, '--compare' => 'anthropic,gemini', '--case' => ['catalog.solo.price', 'code.greeting.en'], '--report' => $this->evalReportName()])
            ->expectsOutputToContain('# Lumi eval comparison')
            ->expectsOutputToContain('| | anthropic | gemini |')
            ->expectsOutputToContain('| Overall pass rate | 100% | 50% |')
            ->expectsOutputToContain('- catalog.solo.price: anthropic pass, gemini fail')
            ->assertExitCode(1);

        $this->assertCount(1, $claude->requests);
        $this->assertCount(1, $gemini->requests);
        $this->assertFileExists($this->evalFile('run-anthropic'));
        $this->assertFileExists($this->evalFile('run-gemini'));
        $this->assertFileExists($this->evalFile('run-compare'));
    }

    public function test_no_report_holds_a_key_or_a_prompt(): void
    {
        config(['assistant.anthropic.api_key' => 'sk-ant-test-SECRET-key-123', 'assistant.api_key' => 'gemini-SECRET-key-456']);
        // A failing case, so its reply is printed
        $this->fake->push(self::reply('answer', 'I am not sure.'));

        $this->artisan('assistant:eval', ['--case' => ['catalog.solo.price', 'edge.phone_redacted', 'code.hours.en'], '--report' => $this->evalReportName()]);

        foreach (['md', 'json'] as $extension) {
            $text = (string) file_get_contents($this->evalFile('run', $extension));

            foreach (['SECRET', 'sk-ant', 'SHOP KNOWLEDGE', 'CATALOG INDEX', 'You are Lumi', 'RULES FOR THESE RECORDS', 'ANSWER ONLY THE LATEST'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $text, "{$forbidden} in the .{$extension} report");
            }
        }

        // The failed case's reply is there, truncated to 400 characters at most
        $this->assertStringContainsString('I am not sure.', (string) file_get_contents($this->evalFile('run')));
    }

    public function test_the_report_holds_the_numbers_the_panel_asks_for(): void
    {
        $this->fake->push(self::reply('answer', 'The Solo Portrait Session is ₱1,200.00.'));

        $this->artisan('assistant:eval', ['--case' => ['catalog.solo.price', 'code.hours.en', 'review.tone_upset'], '--report' => $this->evalReportName()]);

        $markdown = (string) file_get_contents($this->evalFile('run'));
        foreach (['Overall pass rate', 'must_never', 'Latency p50', 'p95', 'Tokens (fake)', 'Estimated cost', 'estimate from config assistant.pricing, verify prices',
            'answered by code: 1', 'failovers: 0', 'provider failure rate', '## By category', '## Review cases', 'review.tone_upset', 'Provider: fake (no fallback)'] as $part) {
            $this->assertStringContainsString($part, $markdown, $part);
        }

        $json = $this->report();
        $this->assertSame(1, $json['review']);
        $this->assertSame(2, $json['scored'], 'The review case is never scored');
    }
}
