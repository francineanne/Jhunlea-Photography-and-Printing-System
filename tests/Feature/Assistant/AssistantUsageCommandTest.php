<?php

namespace Tests\Feature\Assistant;

use App\Enums\AssistantFeature;
use App\Models\AssistantRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** `php artisan assistant:usage` (Phase 7E-2a): a read-only report of assistant_runs with an estimated cost. */
class AssistantUsageCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-05 14:00', 'Asia/Manila')->utc());
        config(['assistant.pricing' => [
            'usd_to_php' => 50.0,
            'models' => ['claude-haiku-5-5' => ['input' => 0.10, 'output' => 0.50, 'cache_read' => 0.01, 'cache_write' => 0.125]],
        ]]);
    }

    private function runRow(array $attributes, ?Carbon $at = null): void
    {
        $run = new AssistantRun($attributes + ['feature' => AssistantFeature::AskLumi]);
        $run->created_at = $at ?? now();
        $run->save();
    }

    private function seedRuns(): void
    {
        // Two Claude answers: 1,000,000 input, 100,000 output, 2,000,000 cache read, 400,000 cache write together
        $this->runRow(['provider' => 'anthropic', 'model' => 'claude-haiku-5-5', 'outcome' => 'answered', 'input_tokens' => 600000, 'output_tokens' => 60000, 'cache_read_tokens' => 2000000, 'cache_write_tokens' => 0, 'latency_ms' => 900]);
        $this->runRow(['provider' => 'anthropic', 'model' => 'claude-haiku-5-5', 'outcome' => 'answered', 'input_tokens' => 400000, 'output_tokens' => 40000, 'cache_read_tokens' => 0, 'cache_write_tokens' => 400000, 'latency_ms' => 800]);
        // A failed Claude call, then the backup took it
        $this->runRow(['provider' => 'anthropic', 'model' => 'claude-haiku-5-5', 'outcome' => 'fallback_unavailable', 'latency_ms' => 50]);
        $this->runRow(['provider' => 'gemini', 'model' => 'gemini-3.5-flash-lite', 'fallback_from' => 'anthropic', 'outcome' => 'answered', 'input_tokens' => 5000, 'output_tokens' => 100, 'latency_ms' => 700]);
        // Three answers by code, one of them in the studio thread
        $this->runRow(['provider' => 'code', 'outcome' => 'answered_by_code', 'intent' => 'answer']);
        $this->runRow(['provider' => 'code', 'outcome' => 'answered_by_code', 'intent' => 'answer']);
        $this->runRow(['provider' => 'code', 'outcome' => 'answered_by_code', 'intent' => 'answer', 'feature' => AssistantFeature::StudioAway]);
        // Older than the period: never counted
        $this->runRow(['provider' => 'anthropic', 'model' => 'claude-haiku-5-5', 'outcome' => 'answered', 'input_tokens' => 999999999, 'latency_ms' => 1], Carbon::parse('2026-10-01 10:00', 'UTC'));
    }

    public function test_the_report_counts_calls_failovers_tokens_code_answers_and_an_estimated_cost(): void
    {
        $this->seedRuns();

        // Claude: 1M x 0.10 + 0.1M x 0.50 + 2M x 0.01 + 0.4M x 0.125 = 0.10 + 0.05 + 0.02 + 0.05 = $0.22 = ₱11.00
        $this->artisan('assistant:usage', ['--days' => 7])
            ->expectsOutputToContain('Assistant usage from 2026-10-30 to 2026-11-05 (Manila days: 7)')
            ->expectsTable(
                ['Provider', 'Feature', 'Model', 'Calls', 'Outcomes', 'Failovers', 'Input', 'Output', 'Cache read', 'Cache write', 'Cost (estimate)'],
                [
                    ['anthropic', 'ask_lumi', 'claude-haiku-5-5', 3, 'answered 2, fallback_unavailable 1', 0, '1,000,000', '100,000', '2,000,000', '400,000', '$0.2200 / ₱11.00'],
                    ['code', 'ask_lumi', '-', 0, 'answered_by_code 2', 0, '0', '0', '0', '0', '0'],
                    ['code', 'studio_away', '-', 0, 'answered_by_code 1', 0, '0', '0', '0', '0', '0'],
                    ['gemini', 'ask_lumi', 'gemini-3.5-flash-lite', 1, 'answered 1', 1, '5,000', '100', '0', '0', 'no price'],
                ],
            )
            ->expectsOutputToContain('Provider calls: 4. Failovers to the backup: 1.')
            ->expectsOutputToContain('Answered by code: 3 of 6 replies (50.0%), with no provider call.')
            ->expectsOutputToContain('Estimated cost: $0.2200 (about ₱11.00 at ₱50.00 per USD).')
            ->expectsOutputToContain('This is an ESTIMATE from config assistant.pricing: verify the prices')
            ->assertSuccessful();
    }

    public function test_a_gemini_model_with_a_verified_price_and_one_without(): void
    {
        // The real price table of config/assistant.php (7E-2c: Gemini rows verified on Google's pricing page)
        $pricing = (require config_path('assistant.php'))['pricing'];
        $this->assertSame(['input' => 0.25, 'output' => 1.50, 'cache_read' => 0.025], $pricing['models']['gemini-3.1-flash-lite']);
        $this->assertSame(['input' => 0.30, 'output' => 2.50, 'cache_read' => 0.03], $pricing['models']['gemini-3.5-flash-lite']);
        config(['assistant.pricing' => ['usd_to_php' => 50.0, 'models' => $pricing['models']]]);

        // 1M input x 0.25 + 0.1M output x 1.50 = $0.40 = ₱20.00
        $this->runRow(['provider' => 'gemini', 'model' => 'gemini-3.1-flash-lite', 'outcome' => 'answered', 'input_tokens' => 1000000, 'output_tokens' => 100000, 'latency_ms' => 500]);
        $this->runRow(['provider' => 'gemini', 'model' => 'gemini-2.0-flash', 'outcome' => 'answered', 'input_tokens' => 5000, 'output_tokens' => 100, 'latency_ms' => 500]);

        $this->artisan('assistant:usage', ['--days' => 1])
            ->expectsTable(
                ['Provider', 'Feature', 'Model', 'Calls', 'Outcomes', 'Failovers', 'Input', 'Output', 'Cache read', 'Cache write', 'Cost (estimate)'],
                [
                    ['gemini', 'ask_lumi', 'gemini-2.0-flash', 1, 'answered 1', 0, '5,000', '100', '0', '0', 'no price'],
                    ['gemini', 'ask_lumi', 'gemini-3.1-flash-lite', 1, 'answered 1', 0, '1,000,000', '100,000', '0', '0', '$0.4000 / ₱20.00'],
                ],
            )
            ->expectsOutputToContain('Models marked "no price" are not counted.')
            ->assertSuccessful();
    }

    public function test_an_empty_period_and_a_bad_days_value(): void
    {
        $this->artisan('assistant:usage')->expectsOutputToContain('No assistant runs in this period.')->assertSuccessful();
        $this->artisan('assistant:usage', ['--days' => 0])->expectsOutputToContain('--days must be a whole number from 1 to 366.')->assertFailed();
        $this->artisan('assistant:usage', ['--days' => 'abc'])->assertFailed();
    }

    public function test_it_writes_nothing(): void
    {
        $this->seedRuns();
        $before = AssistantRun::query()->orderBy('id')->get()->toArray();

        $this->artisan('assistant:usage', ['--days' => 30])->assertSuccessful();

        $this->assertSame($before, AssistantRun::query()->orderBy('id')->get()->toArray());
    }
}
