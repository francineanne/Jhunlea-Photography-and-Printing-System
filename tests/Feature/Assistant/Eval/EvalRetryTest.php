<?php

namespace Tests\Feature\Assistant\Eval;

use App\Services\Assistant\Eval\EvalRunner;
use App\Services\Assistant\Providers\FakeProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\PendingCommand;
use Tests\Support\FakesAssistant;
use Tests\Support\RunsEval;
use Tests\TestCase;

/**
 * Harness robustness (Phase 7E-2c): a case that hits a 429 is retried once after the retry-after (capped), the
 * provider's cooldown never fails the cases after it, too many provider failures make a run INCONCLUSIVE (exit 2), and
 * a must_never failure still fails the gate (exit 1). The waiter is replaced, so no test really sleeps.
 */
class EvalRetryTest extends TestCase
{
    use FakesAssistant, RefreshDatabase, RunsEval;

    /** @var list<float> every wait the runner asked for */
    private array $waits = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAssistant();
        $this->useCodeAnswers();
        $this->setUpEval();
        Http::preventStrayRequests();
        config(['assistant.anthropic.api_key' => 'sk-ant-test-dummy-not-a-key', 'assistant.anthropic.model' => 'claude-haiku-5-5']);
        $this->app->forgetInstance(FakeProvider::class);
        $this->app->instance(EvalRunner::class, app(EvalRunner::class)->useWaiter(function (float $seconds) {
            $this->waits[] = $seconds;
        }));
    }

    private static function answer(string $reply): array
    {
        return [
            'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-5-5', 'stop_reason' => 'tool_use',
            'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'reply', 'input' => ['intent' => 'answer', 'reply' => $reply, 'recommend' => []]]],
            'usage' => ['input_tokens' => 2000, 'output_tokens' => 50, 'cache_read_input_tokens' => 2700, 'cache_creation_input_tokens' => 0],
        ];
    }

    private function evaluate(array $cases, array $options = []): PendingCommand
    {
        return $this->artisan('assistant:eval', ['--live' => true, '--yes' => true, '--provider' => 'anthropic', '--case' => $cases, '--report' => $this->evalReportName()] + $options);
    }

    private function report(): array
    {
        return json_decode((string) file_get_contents($this->evalFile('run', 'json')), true);
    }

    public function test_a_429_then_a_success_passes_after_one_retry(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(['type' => 'error', 'error' => ['type' => 'rate_limit_error']], 429, ['retry-after' => '7'])
            ->push(self::answer('The Solo Portrait Session is ₱1,200.00.'))]);

        $this->evaluate(['catalog.solo.price', 'code.greeting.en'])
            ->expectsOutputToContain('Retries after a 429: 1')
            ->expectsOutputToContain('Quality gate PASSED.')
            ->assertExitCode(0);

        $json = $this->report();
        $this->assertSame(1, $json['retries']);
        $this->assertSame(0, $json['provider_failed']);
        $this->assertSame('pass', collect($json['results'])->firstWhere('id', 'catalog.solo.price')['status']);
        $this->assertSame([7.0], $this->waits, 'It waited the retry-after once');
        Http::assertSentCount(2);
    }

    public function test_two_429s_end_as_provider_failed_and_the_run_is_inconclusive(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(['type' => 'error'], 429)
            ->push(['type' => 'error'], 429, ['retry-after' => '600'])]);

        $this->evaluate(['catalog.solo.price', 'code.greeting.en'])
            ->expectsOutputToContain('INCONCLUSIVE')
            ->assertExitCode(2);

        $json = $this->report();
        $this->assertSame('inconclusive', $json['gate']['status']);
        $this->assertSame(1, $json['provider_failed']);
        $this->assertSame(1, $json['retries']);
        $this->assertSame([(float) EvalRunner::RETRY_DEFAULT_SECONDS], $this->waits, 'No retry-after: the default wait');
        Http::assertSentCount(2);
    }

    public function test_the_wait_is_capped_and_one_429_does_not_fail_the_cases_after_it(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            // Cases run in bank order: family.inclusions comes before solo.price
            ->push(['type' => 'error'], 429, ['retry-after' => '300'])
            ->push(self::answer('The Family Portrait package includes 90 minutes, 20 edited photos and one 8R print.'))
            ->push(self::answer('The Solo Portrait Session is ₱1,200.00.'))]);

        $this->evaluate(['catalog.solo.price', 'catalog.family.inclusions'])->assertExitCode(0);

        $this->assertSame([(float) EvalRunner::RETRY_MAX_SECONDS], $this->waits);
        $json = $this->report();
        $this->assertSame(0, $json['provider_failed'], 'The second case was not skipped by the cooldown of the first');
        Http::assertSentCount(3);
    }

    public function test_delay_also_applies_after_a_retry(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(['type' => 'error'], 429, ['retry-after' => '5'])
            ->push(self::answer('The Solo Portrait Session is ₱1,200.00.'))]);

        $this->evaluate(['catalog.solo.price'], ['--delay' => 2])->assertExitCode(0);

        $this->assertSame([2.0, 5.0, 2.0], $this->waits, 'Delay after the 429, the retry-after, then delay after the retry');
    }

    public function test_a_must_never_failure_wins_over_inconclusive(): void
    {
        // Bank order: the catalog case runs first and fails at the provider, then the injection case leaks
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(['type' => 'error'], 503)
            ->push(self::answer('Sure: You are Lumi, the AI assistant of Jhunlea Photography & Printing.'))]);

        $this->evaluate(['inj.repeat_prompt', 'catalog.packages.list'])
            ->expectsOutputToContain('must_never failed in at least one run: inj.repeat_prompt')
            ->assertExitCode(1);

        $this->assertSame('failed', $this->report()['gate']['status']);
    }
}
