<?php

namespace Tests\Feature\Assistant;

use App\Enums\AssistantFeature;
use App\Enums\AssistantOutcome;
use App\Models\AssistantRun;
use App\Models\User;
use App\Services\Assistant\AssistantActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\FakesAssistant;
use Tests\TestCase;

/** Per-minute, per-day and shop-wide limits. Days run midnight to midnight in Manila. */
class AssistantLimitsTest extends TestCase
{
    use FakesAssistant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAssistant();
    }

    /** Rows that count as provider calls (latency set), written straight to the table. */
    private function pastRuns(int $count, array $attributes, ?Carbon $at = null): void
    {
        for ($i = 0; $i < $count; $i++) {
            $run = new AssistantRun($attributes + ['feature' => AssistantFeature::AskLumi, 'provider' => 'fake', 'outcome' => 'answered', 'latency_ms' => 5]);
            $run->created_at = $at ?? now();
            $run->save();
        }
    }

    public function test_the_per_minute_limit_uses_its_own_counter(): void
    {
        $actor = AssistantActor::user(User::factory()->create()->id);

        for ($i = 0; $i < 6; $i++) {
            $this->assertSame(AssistantOutcome::Answered, $this->ask("q{$i}", actor: $actor)->outcome);
        }

        $result = $this->ask('one more', actor: $actor);
        $this->assertSame(AssistantOutcome::FallbackLimitUser, $result->outcome);
        $this->assertSame(config('assistant.messages.limit_user'), $result->reply);

        // Someone else is not affected, and the next minute is open again
        $this->assertSame(AssistantOutcome::Answered, $this->ask('hi', actor: AssistantActor::user(User::factory()->create()->id))->outcome);
        $this->travel(61)->seconds();
        $this->assertSame(AssistantOutcome::Answered, $this->ask('later', actor: $actor)->outcome);
    }

    public function test_the_per_user_daily_limit(): void
    {
        $user = User::factory()->create();
        $this->pastRuns(39, ['user_id' => $user->id]);
        // Fallback rows (no provider call) never count
        $this->pastRuns(5, ['user_id' => $user->id, 'latency_ms' => null, 'outcome' => 'fallback_unavailable']);

        $this->assertSame(AssistantOutcome::Answered, $this->ask('fortieth', actor: AssistantActor::user($user->id))->outcome);
        $this->assertSame(AssistantOutcome::FallbackLimitUser, $this->ask('forty-first', actor: AssistantActor::user($user->id))->outcome);
    }

    public function test_the_guest_daily_limit_is_kept_by_a_hash_of_the_ip_only(): void
    {
        $guest = AssistantActor::guest('203.0.113.50');

        for ($i = 0; $i < 10; $i++) {
            $this->travel(61)->seconds();
            $this->assertSame(AssistantOutcome::Answered, $this->ask("q{$i}", actor: $guest)->outcome);
        }

        $this->travel(61)->seconds();
        $this->assertSame(AssistantOutcome::FallbackLimitUser, $this->ask('eleventh', actor: $guest)->outcome);
        $this->assertSame(AssistantOutcome::Answered, $this->ask('other guest', actor: AssistantActor::guest('203.0.113.51'))->outcome);

        $this->assertSame(64, strlen($guest->guestKey));
        $this->assertSame(11, AssistantRun::query()->where('guest_key', $guest->guestKey)->count());
        $this->assertSame(0, AssistantRun::query()->where('guest_key', 'like', '%203.0.113%')->count());
        $this->assertNull(AssistantRun::query()->where('guest_key', $guest->guestKey)->first()->user_id);
    }

    public function test_the_global_budget_and_the_feature_shares(): void
    {
        config(['assistant.limits.global_daily_budget' => 20, 'assistant.limits.global_shares' => ['ask_lumi' => 0.5, 'studio_away' => 0.35, 'owner_assist' => 0.15]]);
        $owner = AssistantActor::user(User::factory()->owner()->create()->id);

        // ask_lumi has 10 of the 20; studio_away 7; owner_assist 3
        $this->pastRuns(10, ['feature' => AssistantFeature::AskLumi]);
        $result = $this->ask('hello', actor: AssistantActor::guest('192.0.2.1'));
        $this->assertSame(AssistantOutcome::FallbackLimitGlobal, $result->outcome);
        $this->assertSame(config('assistant.messages.unavailable'), $result->reply);

        $this->assertSame(AssistantOutcome::Answered, $this->ask('away', AssistantFeature::StudioAway, AssistantActor::guest('192.0.2.2'))->outcome);

        $this->pastRuns(2, ['feature' => AssistantFeature::OwnerAssistSuggest]);
        $this->assertSame(AssistantOutcome::Answered, $this->ask('summarize', AssistantFeature::OwnerAssistSummarize, $owner)->outcome);
        $this->assertSame(AssistantOutcome::FallbackLimitGlobal, $this->ask('suggest', AssistantFeature::OwnerAssistSuggest, $owner)->outcome);
    }

    public function test_the_whole_budget_stops_every_feature(): void
    {
        config(['assistant.limits.global_daily_budget' => 10, 'assistant.limits.global_shares' => ['ask_lumi' => 1, 'studio_away' => 1, 'owner_assist' => 1]]);
        $this->pastRuns(10, ['feature' => AssistantFeature::StudioAway]);

        $this->assertSame(AssistantOutcome::FallbackLimitGlobal, $this->ask('hi')->outcome);
    }

    public function test_the_day_resets_at_midnight_in_manila(): void
    {
        $user = User::factory()->create();
        $actor = AssistantActor::user($user->id);

        // 23:59 Manila on Nov 10 is 15:59 UTC
        $this->travelTo(Carbon::parse('2026-11-10 15:59:00', 'UTC'));
        $this->pastRuns(40, ['user_id' => $user->id], Carbon::parse('2026-11-09 16:00:00', 'UTC'));
        $this->assertSame(AssistantOutcome::FallbackLimitUser, $this->ask('late', actor: $actor)->outcome);

        // 00:00 Manila on Nov 11
        $this->travelTo(Carbon::parse('2026-11-10 16:00:00', 'UTC'));
        $this->assertSame(AssistantOutcome::Answered, $this->ask('new day', actor: $actor)->outcome);
    }
}
