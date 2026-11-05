<?php

namespace Tests\Feature\Assistant\Eval;

use App\Services\Assistant\Eval\CaseBank;
use App\Services\Assistant\Eval\EvalCase;
use App\Services\Assistant\Providers\FakeProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\Support\FakesAssistant;
use Tests\Support\RunsEval;
use Tests\TestCase;

/**
 * The written case bank (Phase 7E-2b): it loads, it is valid, it is big enough, and the whole bank runs with the fake
 * provider and through the real Claude provider class (HTTP faked) without one harness error.
 */
class EvalCaseBankTest extends TestCase
{
    use FakesAssistant, RefreshDatabase, RunsEval;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAssistant();
        $this->useCodeAnswers();
        $this->setUpEval();
        $this->tmp = storage_path('framework/testing/eval-cases-'.bin2hex(random_bytes(4)));
        @mkdir($this->tmp, 0775, true);
        $this->beforeApplicationDestroyed(function () {
            array_map('unlink', glob($this->tmp.'/*') ?: []);
            @rmdir($this->tmp);
        });
    }

    public function test_the_bank_is_valid_and_big_enough(): void
    {
        $cases = app(CaseBank::class)->load();

        // 7E-2c: at least 175 cases and 55 must_never (were 110 and 15 in 7E-2b)
        $this->assertGreaterThanOrEqual(175, count($cases));
        $this->assertGreaterThanOrEqual(55, count(array_filter($cases, fn (EvalCase $case) => $case->isMustNever())));
        $this->assertGreaterThanOrEqual(6, count(array_filter($cases, fn (EvalCase $case) => $case->isReview())));
        $this->assertSame(count($cases), count(array_unique(array_map(fn (EvalCase $case) => $case->id, $cases))));
        $this->assertEqualsCanonicalizing(CaseBank::CATEGORIES, array_values(array_unique(array_map(fn (EvalCase $case) => $case->category, $cases))));

        $minimum = ['code_answers' => 15, 'catalog' => 15, 'taglish_synonyms' => 10, 'price' => 12, 'booking_rules' => 8, 'account_aware' => 18, 'claims' => 18,
            'isolation' => 8, 'injection' => 16, 'off_topic' => 6, 'handoff' => 10, 'time' => 8, 'language' => 8, 'edge' => 11, 'studio_away' => 10, 'review' => 6];
        $counts = array_count_values(array_map(fn (EvalCase $case) => $case->category, $cases));

        foreach ($minimum as $category => $count) {
            $this->assertGreaterThanOrEqual($count, $counts[$category] ?? 0, $category);
        }

        // Every price, claims, isolation and injection case is must_never (cg.sarcasm again since 7E-2c), and the
        // past-shoot cases too: that 7E-2a bug must never come back
        foreach (array_filter($cases, fn (EvalCase $case) => in_array($case->category, ['price', 'claims', 'isolation', 'injection'], true)) as $case) {
            $this->assertTrue($case->isMustNever(), $case->id);
        }

        foreach (['acct.past_shoot', 'acct.past_shoot.fil', 'studio.no_owner_promise'] as $id) {
            $this->assertTrue(collect($cases)->firstWhere('id', $id)->isMustNever(), $id);
        }

        // ClaimGuard cases cost nothing, and two cases move the clock across the Manila midnight
        $this->assertGreaterThanOrEqual(6, count(array_filter($cases, fn (EvalCase $case) => $case->feature === 'claimguard')));
        $this->assertSame(['2026-11-08 23:30', '2026-11-09 00:30'], array_values(array_filter(array_map(fn (EvalCase $case) => $case->now, $cases), fn ($now) => in_array($now, ['2026-11-08 23:30', '2026-11-09 00:30'], true))));
    }

    public function test_invalid_cases_are_refused_with_a_clear_reason(): void
    {
        $bad = [
            ['id' => 'x.one', 'category' => 'catalog', 'severity' => 'quality', 'scenario' => 'guest', 'message' => 'hi', 'expect' => ['reply_has' => ['x']]],
            ['id' => 'x.dup', 'category' => 'catalog', 'severity' => 'quality', 'scenario' => 'guest', 'message' => 'hi', 'expect' => ['outcome' => 'answered']],
            ['id' => 'x.dup', 'category' => 'catalog', 'severity' => 'quality', 'scenario' => 'guest', 'message' => 'hello', 'expect' => ['outcome' => 'answered']],
            ['id' => 'x.two', 'category' => 'price', 'severity' => 'quality', 'scenario' => 'guest', 'message' => 'hi', 'expect' => ['outcome' => 'answered']],
            ['id' => 'x.three', 'category' => 'catalog', 'severity' => 'critical', 'scenario' => 'carl', 'message' => 'hi', 'expect' => ['outcome' => 'clarify']],
            ['id' => 'x.four', 'category' => 'catalog', 'severity' => 'quality', 'scenario' => 'ana', 'feature' => 'claimguard', 'message' => '', 'expect' => ['reply_contains_all' => ['x']]],
            ['id' => 'x.five', 'category' => 'catalog', 'severity' => 'quality', 'scenario' => 'ana', 'message' => 'hi', 'expect' => ['cards_max' => 'three', 'language' => 'es']],
            ['id' => 'x.six', 'category' => 'catalog', 'severity' => 'quality', 'scenario' => 'ana', 'message' => 'hi', 'now' => 'tomorrow', 'expect' => []],
        ];
        file_put_contents($this->tmp.'/catalog.json', json_encode($bad));

        try {
            app(CaseBank::class)->load($this->tmp);
            $this->fail('Invalid cases were accepted');
        } catch (InvalidArgumentException $exception) {
            foreach (['unknown check "reply_has"', 'duplicate id x.dup', 'category "price" belongs in price.json', 'severity must be must_never or quality',
                'unknown scenario', 'check "outcome" has an unknown value: clarify', 'a claimguard case needs the fixed "reply" text',
                'check "cards_max" must be int', 'check "language" has an unknown value: es', 'now must be "YYYY-MM-DD HH:MM"', 'expect must be an object with at least one check'] as $reason) {
                $this->assertStringContainsString($reason, $exception->getMessage());
            }
        }
    }

    public function test_the_whole_bank_runs_with_the_fake_provider_without_a_harness_error(): void
    {
        $count = count(app(CaseBank::class)->load());

        $this->artisan('assistant:eval', ['--report' => $this->evalReportName()]);

        $json = json_decode((string) file_get_contents($this->evalFile('run', 'json')), true);
        $this->assertSame($count, $json['cases']);
        $this->assertSame($count, $json['attempts']);
        $this->assertSame(0, $json['errors'], json_encode(array_filter($json['failures'], fn ($row) => $row['status'] === 'error')));
        $this->assertSame(0, $json['skipped']);
        $this->assertSame(0, $json['provider_failed']);
        $this->assertGreaterThan(0, $json['answered_by_code']);
        $this->assertCount(16, $json['categories']);
    }

    public function test_the_whole_bank_runs_through_the_real_claude_provider_class_with_http_faked(): void
    {
        Http::preventStrayRequests();
        config(['assistant.anthropic.api_key' => 'sk-ant-test-dummy-not-a-key', 'assistant.anthropic.model' => 'claude-haiku-5-5']);
        $this->app->forgetInstance(FakeProvider::class);

        // A plausible, careful Claude reply for every call, with the usage block of a cache hit
        Http::fake(['api.anthropic.com/*' => function (Request $request) {
            $reply = str_contains($request->body(), 'Solo Portrait') ? 'The Solo Portrait Session is ₱1,200.00. You can book it on Book a photoshoot.'
                : 'I am not sure about that from here. Please message the studio in the Studio tab and they will help you.';

            return Http::response([
                'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-5-5', 'stop_reason' => 'tool_use',
                'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'reply', 'input' => ['intent' => 'answer', 'reply' => $reply, 'recommend' => []]]],
                'usage' => ['input_tokens' => 2000, 'output_tokens' => 250, 'cache_read_input_tokens' => 2700, 'cache_creation_input_tokens' => 0],
            ]);
        }]);

        $this->artisan('assistant:eval', ['--live' => true, '--yes' => true, '--provider' => 'anthropic', '--report' => $this->evalReportName()]);

        $json = json_decode((string) file_get_contents($this->evalFile('run', 'json')), true);
        $this->assertSame(0, $json['errors']);
        $this->assertSame(['claude-haiku-5-5'], $json['models']);
        $this->assertSame(['anthropic'], $json['providers_called']);
        $this->assertGreaterThan(100, $json['provider_calls']);
        $this->assertSame($json['provider_calls'] * 2700, $json['tokens']['claude-haiku-5-5']['cache_read']);
        $this->assertGreaterThan(0, $json['cost_usd']);
        $this->assertNotNull($json['latency_p95_ms']);
        Http::assertSentCount($json['provider_calls']);

        // The careful reply passes many must_never cases and fails the quality ones that need real content
        $this->assertGreaterThan(30, collect($json['results'])->where('severity', 'must_never')->where('status', 'pass')->count());
        $this->assertSame('pass', collect($json['results'])->firstWhere('id', 'catalog.solo.price')['status']);
    }
}
