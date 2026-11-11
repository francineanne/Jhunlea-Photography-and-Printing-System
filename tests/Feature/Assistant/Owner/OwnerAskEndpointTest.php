<?php

namespace Tests\Feature\Assistant\Owner;

use App\Models\OwnerAssistantMessage;
use App\Models\User;
use App\Services\Assistant\Owner\OwnerTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\FakesAssistant;
use Tests\Support\SeedsOwnerShop;
use Tests\TestCase;

/**
 * The owner's Ask Lumi endpoints (Phase 7E-3a): POST /owner/assistant/ask, GET /owner/assistant/history and GET
 * /owner/assistant/briefing. Owner only; validation; the named throttle; the JSON shape; the owner's own history; the
 * briefing without any provider call.
 */
class OwnerAskEndpointTest extends TestCase
{
    use FakesAssistant, RefreshDatabase, SeedsOwnerShop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAssistant();
        $this->seedOwnerShop();
    }

    public function test_only_the_owner_may_ask(): void
    {
        $this->postJson(route('owner.assistant.ask'), ['message' => 'Income today?'])->assertUnauthorized();
        $this->getJson(route('owner.assistant.history'))->assertUnauthorized();
        $this->getJson(route('owner.assistant.briefing'))->assertUnauthorized();

        $customer = User::factory()->create();
        $this->actingAs($customer)->postJson(route('owner.assistant.ask'), ['message' => 'Income today?'])->assertForbidden();
        $this->actingAs($customer)->getJson(route('owner.assistant.history'))->assertForbidden();
        $this->actingAs($customer)->getJson(route('owner.assistant.briefing'))->assertForbidden();

        $this->assertSame([], $this->fake->requests);
        $this->assertSame(0, OwnerAssistantMessage::query()->count());
    }

    public function test_the_answer_json_and_the_history(): void
    {
        $this->fake->push(OwnerAskServiceTest::plan([['unpaid_balances', []]]), self::reply('answer', 'Here is what is still unpaid.'));

        $this->actingAs($this->owner)->postJson(route('owner.assistant.ask'), ['message' => 'What is unpaid?', 'role' => 'admin'])
            ->assertOk()
            ->assertExactJson([
                'reply' => 'Here is what is still unpaid.',
                'cards' => $this->fakeCards(),
                // 8D: unpaid balances also link the counter's Awaiting payment queue
                'links' => [['label' => 'Counter: awaiting payment', 'url' => '/owner/payments?awaiting=1'], ['label' => 'Payments ledger', 'url' => '/owner/payments']],
                'tools_used' => ['unpaid_balances'],
                'via_backup' => false,
                'notice' => null,
            ]);

        $history = $this->actingAs($this->owner)->getJson(route('owner.assistant.history'))->assertOk()->json('messages');
        $this->assertSame(['owner', 'lumi'], array_column($history, 'role'));
        $this->assertSame('What is unpaid?', $history[0]['text']);
        $this->assertSame('Here is what is still unpaid.', $history[1]['text']);

        // The next question reads the earlier turns (never tool data); "fresh" leaves them out
        $this->fake->push(OwnerAskServiceTest::plan([], 'clarify', 'Which one?'));
        $this->actingAs($this->owner)->postJson(route('owner.assistant.ask'), ['message' => 'And the oldest?'])->assertOk();
        $this->assertCount(3, end($this->fake->requests)->messages);

        $this->fake->push(OwnerAskServiceTest::plan([], 'clarify', 'Which one?'));
        $this->actingAs($this->owner)->postJson(route('owner.assistant.ask'), ['message' => 'Start over', 'fresh' => true])->assertOk();
        $this->assertCount(1, end($this->fake->requests)->messages);

        // Another owner never sees this conversation
        $other = User::factory()->owner()->create();
        $this->assertSame([], $this->actingAs($other)->getJson(route('owner.assistant.history'))->json('messages'));
    }

    public function test_validation(): void
    {
        $this->actingAs($this->owner)->postJson(route('owner.assistant.ask'), [])->assertStatus(422)->assertJson(['code' => 'validation']);
        $this->actingAs($this->owner)->postJson(route('owner.assistant.ask'), ['message' => str_repeat('a', (int) config('chat.message_max_chars') + 1)])->assertStatus(422);
        $this->actingAs($this->owner)->postJson(route('owner.assistant.ask'), ['message' => ['x']])->assertStatus(422);
        $this->assertSame([], $this->fake->requests);
    }

    public function test_the_named_throttle(): void
    {
        RateLimiter::clear('owner-ask-question');

        for ($i = 0; $i < 20; $i++) {
            $this->fake->push(OwnerAskServiceTest::plan([], 'clarify', 'Which?'));
            $this->actingAs($this->owner)->postJson(route('owner.assistant.ask'), ['message' => "q{$i}"])->assertOk();
        }

        $this->actingAs($this->owner)->postJson(route('owner.assistant.ask'), ['message' => 'one more'])->assertStatus(429);
    }

    public function test_the_briefing_makes_no_provider_call(): void
    {
        Http::fake();

        $json = $this->actingAs($this->owner)->getJson(route('owner.assistant.briefing'))->assertOk()->json();

        $this->assertStringContainsString('Recorded payments today: ₱', $json['reply']);
        $this->assertSame(['briefing'], $json['tools_used']);
        $this->assertFalse($json['via_backup']);
        $this->assertSame([], $this->fake->requests);
        Http::assertNothingSent();
    }

    /** @return list<array{label: string, url: string}> the unpaid tool's cards, read the same way */
    private function fakeCards(): array
    {
        return app(OwnerTools::class)->get('unpaid_balances')->execute([], $this->owner)->cards;
    }
}
