<?php

namespace Tests\Feature\Assistant;

use App\Enums\AssistantFeature;
use App\Enums\AssistantOutcome;
use App\Models\AssistantRun;
use App\Models\User;
use App\Services\Assistant\AssistantActor;
use App\Services\Assistant\Providers\GeminiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakesAssistant;
use Tests\TestCase;

/**
 * A call counts toward the limits only when the provider sent an HTTP response. Uses the real Gemini provider with
 * Http::fake (never the network), so the failures are the ones the provider really produces.
 */
class AssistantCountingTest extends TestCase
{
    use FakesAssistant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['assistant.provider' => 'gemini', 'assistant.api_key' => 'test-key', 'assistant.enabled' => true]);
    }

    private static function geminiBody(string $json): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => $json]]]]], 'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5]];
    }

    public function test_connection_failures_never_use_up_any_limit(): void
    {
        config(['assistant.limits' => ['per_minute_per_user' => 2, 'per_day_per_user' => 3, 'per_day_per_guest_ip' => 3,
            'global_daily_budget' => 4, 'global_shares' => ['ask_lumi' => 1, 'studio_away' => 1, 'owner_assist' => 1],
            'studio_away_max_consecutive' => 5, 'provider_cooldown_max_seconds' => 300] + config('assistant.limits')]);

        $connected = false;
        Http::fake(function () use (&$connected) {
            if (! $connected) {
                throw new ConnectionException('cURL error 60: SSL certificate problem for '.GeminiProvider::ENDPOINT);
            }

            return Http::response(self::geminiBody('{"intent":"answer","reply":"Hi!","recommend":[]}'));
        });

        $user = AssistantActor::user(User::factory()->create()->id);
        $guest = AssistantActor::guest('203.0.113.9');

        // Far past every limit, in the same minute: each call still reaches the provider and fails there
        for ($i = 0; $i < 8; $i++) {
            $this->assertSame(AssistantOutcome::FallbackUnavailable, $this->ask("u{$i}", actor: $user)->outcome);
            $this->assertSame(AssistantOutcome::FallbackUnavailable, $this->ask("g{$i}", actor: $guest)->outcome);
        }

        Http::assertSentCount(0);
        $this->assertSame(16, AssistantRun::query()->count());
        $this->assertSame(0, AssistantRun::query()->calledProvider()->count());
        $this->assertSame(0, AssistantRun::query()->where('outcome', AssistantOutcome::FallbackLimitUser)->count());
        $this->assertSame(0, AssistantRun::query()->where('outcome', AssistantOutcome::FallbackLimitGlobal)->count());

        // Once the connection works, the full limits are still there
        $connected = true;
        $this->assertSame(AssistantOutcome::Answered, $this->ask('works now', actor: $user)->outcome);
        $this->assertSame(AssistantOutcome::Answered, $this->ask('works now', actor: $user)->outcome);
        $this->assertSame(AssistantOutcome::FallbackLimitUser, $this->ask('third in a minute', actor: $user)->outcome);
    }

    public function test_answers_429_500_and_bad_json_all_count(): void
    {
        $responses = [
            [Http::response(self::geminiBody('{"intent":"answer","reply":"Hi!","recommend":[]}')), AssistantOutcome::Answered],
            [Http::response(['error' => ['code' => 500]], 500), AssistantOutcome::FallbackUnavailable],
            [Http::response(self::geminiBody('this is not json')), AssistantOutcome::FallbackError],
            [Http::response(['error' => ['code' => 400]], 400), AssistantOutcome::FallbackUnavailable],
            [Http::response(['error' => ['code' => 429]], 429, ['Retry-After' => '5']), AssistantOutcome::FallbackUnavailable],
        ];

        $sequence = Http::sequence();
        foreach ($responses as [$response]) {
            $sequence->pushResponse($response);
        }
        $sequence->pushResponse(Http::response(self::geminiBody('{"intent":"answer","reply":"Hi!","recommend":[]}')));
        Http::fake(['*' => $sequence]);

        $actor = AssistantActor::user(User::factory()->create()->id);

        foreach ($responses as $i => [, $outcome]) {
            $this->assertSame($outcome, $this->ask("call {$i}", actor: $actor)->outcome);
        }

        $this->assertSame(5, AssistantRun::query()->calledProvider()->where('user_id', $actor->userId)->count());

        // Five answered calls used up five of the six a minute: one more is allowed (after the 429 cooldown), then none
        $this->travel(6)->seconds();
        $this->assertSame(AssistantOutcome::Answered, $this->ask('sixth', actor: $actor)->outcome);
        $this->assertSame(AssistantOutcome::FallbackLimitUser, $this->ask('seventh', actor: $actor)->outcome);
    }

    public function test_a_missing_key_or_unknown_provider_never_counts(): void
    {
        $actor = AssistantActor::guest('198.51.100.20');

        config(['assistant.api_key' => null]);
        for ($i = 0; $i < 12; $i++) {
            $this->assertSame(AssistantOutcome::FallbackUnavailable, $this->ask("no key {$i}", actor: $actor)->outcome);
        }

        config(['assistant.provider' => 'unknown']);
        $this->assertSame(AssistantOutcome::FallbackUnavailable, $this->ask('unknown', actor: $actor)->outcome);

        $this->assertSame(13, AssistantRun::query()->count());
        $this->assertSame(0, AssistantRun::query()->calledProvider()->count());

        $this->fakeAssistant();
        $this->assertSame(AssistantOutcome::Answered, $this->ask('fake works', AssistantFeature::AskLumi, $actor)->outcome);
    }
}
