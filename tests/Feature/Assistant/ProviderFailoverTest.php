<?php

namespace Tests\Feature\Assistant;

use App\Enums\AssistantOutcome;
use App\Models\AssistantRun;
use App\Models\User;
use App\Services\Assistant\AssistantActor;
use App\Services\Assistant\Exceptions\ProviderBadResponse;
use App\Services\Assistant\Exceptions\ProviderRateLimited;
use App\Services\Assistant\Exceptions\ProviderUnavailable;
use App\Services\Assistant\LimitService;
use App\Services\Assistant\Providers\FakeProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FakesAssistant;
use Tests\TestCase;

/**
 * Claude answers first and Gemini takes over only on provider trouble (Phase 7E-1): a 429, an outage or a broken body.
 * A real answer, a refusal and a reply the validator or a guard refuses never go to the backup.
 */
class ProviderFailoverTest extends TestCase
{
    use FakesAssistant, RefreshDatabase;

    private FakeProvider $claude;

    private FakeProvider $gemini;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->claude, $this->gemini] = $this->fakeChain();
    }

    public function test_a_healthy_claude_answers_and_gemini_is_never_asked(): void
    {
        $this->claude->push(self::reply('answer', 'Claude here po.'));

        $result = $this->ask('Hello');

        $this->assertSame('Claude here po.', $result->reply);
        $this->assertCount(1, $this->claude->requests);
        $this->assertSame([], $this->gemini->requests);
        $run = AssistantRun::query()->sole();
        $this->assertSame('anthropic', $run->provider);
        $this->assertNull($run->fallback_from);
    }

    public function test_each_kind_of_provider_trouble_fails_over_to_gemini(): void
    {
        $failures = [
            'rate limited' => new ProviderRateLimited(20),
            'overloaded 529' => new ProviderUnavailable(ProviderUnavailable::HTTP, 529),
            'server error' => new ProviderUnavailable(ProviderUnavailable::HTTP, 500),
            'refused key' => new ProviderUnavailable(ProviderUnavailable::HTTP, 401, reason: 'auth'),
            'no credit' => new ProviderUnavailable(ProviderUnavailable::HTTP, 400, reason: 'credit'),
            'timeout' => new ProviderUnavailable('connection', curlError: 28),
            'broken body' => new ProviderBadResponse('The AI provider sent no content.'),
            'no tool call' => new ProviderBadResponse('No tool', 'no_tool_use'),
        ];

        foreach ($failures as $name => $failure) {
            $this->travel(10)->minutes(); // past any cooldown the previous case started
            $this->claude->push($failure);
            $this->gemini->push(self::reply('answer', "Gemini answers ({$name})."));

            $result = $this->ask("Question {$name}", actor: AssistantActor::guest('198.51.100.'.crc32($name) % 250));

            $this->assertSame(AssistantOutcome::Answered, $result->outcome, $name);
            $this->assertSame("Gemini answers ({$name}).", $result->reply, $name);

            $run = AssistantRun::query()->latest('id')->first();
            $this->assertSame('gemini', $run->provider, $name);
            $this->assertSame('anthropic', $run->fallback_from, $name);
        }

        $this->assertCount(count($failures), $this->gemini->requests);
    }

    public function test_a_real_answer_of_any_intent_never_goes_to_the_backup(): void
    {
        foreach (['answer', 'recommend', 'clarify', 'handoff', 'off_topic'] as $intent) {
            $this->claude->push(self::reply($intent, "Reply with {$intent}."));
            $result = $this->ask("Ask {$intent}");

            $this->assertNotSame(AssistantOutcome::FallbackUnavailable, $result->outcome, $intent);
        }

        $this->assertCount(5, $this->claude->requests);
        $this->assertSame([], $this->gemini->requests);
        $this->assertSame(0, AssistantRun::query()->whereNotNull('fallback_from')->count());
    }

    public function test_a_refusal_never_goes_to_the_backup(): void
    {
        $this->claude->push(ProviderBadResponse::refusal());

        $result = $this->ask('Something Claude declines');

        $this->assertSame(AssistantOutcome::FallbackError, $result->outcome);
        $this->assertSame(config('assistant.messages.unavailable'), $result->reply);
        $this->assertSame([], $this->gemini->requests);
    }

    public function test_a_reply_the_validator_or_a_guard_refuses_never_goes_to_the_backup(): void
    {
        // The validator: not the contract
        $this->claude->push('{"intent": "shout", "reply": "x"}');
        $this->assertSame(AssistantOutcome::FallbackError, $this->ask('one')->outcome);

        // The prompt-leak guard
        $this->claude->push(self::reply('answer', 'My rules say SHOP KNOWLEDGE comes first.'));
        $this->assertSame(AssistantOutcome::FallbackError, $this->ask('two')->outcome);

        // PriceGuard and ClaimGuard remove text and answer with their fixed texts
        $this->claude->push(self::reply('answer', 'The wedding package costs ₱99,999.'));
        $this->assertSame(config('assistant.messages.price_unverified'), $this->ask('three')->reply);

        $this->claude->push(self::reply('answer', 'I cancelled your booking for you.'));
        $this->assertSame(config('assistant.messages.claim_unverified'), $this->ask('four')->reply);

        $this->assertCount(4, $this->claude->requests);
        $this->assertSame([], $this->gemini->requests);
    }

    public function test_both_down_ends_in_the_fixed_fallback(): void
    {
        $this->claude->push(new ProviderUnavailable(ProviderUnavailable::HTTP, 529));
        $this->gemini->push(new ProviderUnavailable(ProviderUnavailable::HTTP, 503));

        $result = $this->ask('Hello');

        $this->assertSame(AssistantOutcome::FallbackUnavailable, $result->outcome);
        $this->assertSame(config('assistant.messages.unavailable'), $result->reply);

        $runs = AssistantRun::query()->orderBy('id')->get();
        $this->assertSame(['anthropic', 'gemini'], $runs->pluck('provider')->all());
        $this->assertSame([null, 'anthropic'], $runs->pluck('fallback_from')->all());

        // Gemini broken too: the last failure decides the outcome
        $this->claude->push(new ProviderRateLimited(5));
        $this->gemini->push(new ProviderBadResponse('broken'));
        $this->assertSame(AssistantOutcome::FallbackError, $this->ask('Again', actor: AssistantActor::guest('198.51.100.99'))->outcome);
    }

    public function test_a_claude_cooldown_skips_claude_but_never_blocks_gemini(): void
    {
        $this->claude->push(new ProviderRateLimited(30));

        $this->assertSame(AssistantOutcome::Answered, $this->ask('first')->outcome);
        $this->assertSame(AssistantOutcome::Answered, $this->ask('second')->outcome);
        $this->assertCount(1, $this->claude->requests, 'Claude waits out its retry-after');
        $this->assertCount(2, $this->gemini->requests);
        $this->assertSame('anthropic', AssistantRun::query()->latest('id')->first()->fallback_from, 'A skipped Claude still names the failover');

        $this->travel(31)->seconds();

        $this->ask('third');
        $this->assertCount(2, $this->claude->requests);
        $this->assertCount(2, $this->gemini->requests);
    }

    public function test_only_calls_a_provider_answered_count_and_both_providers_share_the_budget(): void
    {
        $user = User::factory()->create();
        $actor = AssistantActor::user($user->id);

        // Claude never answered (timeout): only Gemini's call counts
        $this->claude->push(new ProviderUnavailable('connection', curlError: 28));
        $this->ask('one', actor: $actor);
        $this->assertSame(1, AssistantRun::query()->calledProvider()->count());
        $this->assertSame(1, RateLimiter::attempts(LimitService::MINUTE_LIMITER.':'.$actor->key()));

        // Claude answered 529: both calls count, each in its own row
        $this->claude->push(new ProviderUnavailable(ProviderUnavailable::HTTP, 529));
        $this->ask('two', actor: $actor);
        $this->assertSame(3, AssistantRun::query()->calledProvider()->count());
        $this->assertSame(3, RateLimiter::attempts(LimitService::MINUTE_LIMITER.':'.$actor->key()));

        $failed = AssistantRun::query()->where('provider', 'anthropic')->whereNotNull('latency_ms')->sole();
        $this->assertSame(AssistantOutcome::FallbackUnavailable, $failed->outcome);
        $this->assertSame($user->id, $failed->user_id);

        // The daily limit counts every one of them
        config(['assistant.limits.per_day_per_user' => 3]);
        $this->assertSame(AssistantOutcome::FallbackLimitUser, $this->ask('three', actor: $actor)->outcome);
    }

    public function test_a_claude_without_a_key_is_skipped_for_the_backup(): void
    {
        $this->app->forgetInstance('assistant.provider.anthropic');
        $this->app->offsetUnset('assistant.provider.anthropic');
        config(['assistant.anthropic.api_key' => null]);
        $this->gemini->push(self::reply('answer', 'Gemini only.'));

        $this->assertSame('Gemini only.', $this->ask('Hello')->reply);
        $run = AssistantRun::query()->sole();
        $this->assertSame('gemini', $run->provider);
        $this->assertSame('anthropic', $run->fallback_from);
    }

    public function test_no_backup_configured_means_claude_alone(): void
    {
        config(['assistant.fallback_provider' => null]);
        $this->claude->push(new ProviderUnavailable(ProviderUnavailable::HTTP, 529));

        $this->assertSame(AssistantOutcome::FallbackUnavailable, $this->ask('Hello')->outcome);
        $this->assertSame([], $this->gemini->requests);
        $this->assertNull(AssistantRun::query()->sole()->fallback_from);
    }

    public function test_the_row_is_still_written_when_the_fallback_from_column_is_missing(): void
    {
        $this->changesSchema(); // drops or truncates a table: see Tests\TestCase (9B)
        Schema::table('assistant_runs', fn ($table) => $table->dropColumn('fallback_from'));
        $this->claude->push(new ProviderUnavailable(ProviderUnavailable::HTTP, 529));
        $this->gemini->push(self::reply('answer', 'Still answered.'));

        $this->assertSame('Still answered.', $this->ask('Hello')->reply);
        $this->assertSame(['anthropic', 'gemini'], AssistantRun::query()->orderBy('id')->pluck('provider')->all());
    }

    public function test_the_row_is_still_written_when_the_cache_columns_are_missing(): void
    {
        $this->changesSchema(); // drops or truncates a table: see Tests\TestCase (9B)
        // A database where the 7E-2a migration has not run yet
        Schema::table('assistant_runs', fn ($table) => $table->dropColumn(['cache_read_tokens', 'cache_write_tokens']));
        $this->claude->push(self::reply('answer', 'Answered without the cache columns.'));

        $this->assertSame('Answered without the cache columns.', $this->ask('Hello')->reply);
        $run = AssistantRun::query()->sole();
        $this->assertSame('anthropic', $run->provider);
        $this->assertSame(100, $run->input_tokens);

        // And with neither the cache columns nor fallback_from, the backup's row is still written
        Schema::table('assistant_runs', fn ($table) => $table->dropColumn('fallback_from'));
        $this->claude->push(new ProviderUnavailable(ProviderUnavailable::HTTP, 529));
        $this->gemini->push(self::reply('answer', 'Still answered.'));
        $this->assertSame('Still answered.', $this->ask('Hello')->reply);
        $this->assertSame(['anthropic', 'anthropic', 'gemini'], AssistantRun::query()->orderBy('id')->pluck('provider')->all());
    }
}
