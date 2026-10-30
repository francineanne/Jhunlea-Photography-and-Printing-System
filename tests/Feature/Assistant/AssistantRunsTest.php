<?php

namespace Tests\Feature\Assistant;

use App\Enums\AssistantFeature;
use App\Enums\AssistantIntent;
use App\Enums\AssistantOutcome;
use App\Models\AssistantRun;
use App\Models\PhotographyPackage;
use App\Models\User;
use App\Services\Assistant\AssistantActor;
use App\Services\Assistant\Exceptions\ProviderUnavailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FakesAssistant;
use Tests\TestCase;

/** One metadata-only assistant_runs row per call, and the developer command. */
class AssistantRunsTest extends TestCase
{
    use FakesAssistant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAssistant();
    }

    public function test_the_table_has_no_column_that_could_hold_message_text(): void
    {
        $this->assertSame(
            ['id', 'user_id', 'guest_key', 'feature', 'provider', 'model', 'outcome', 'intent', 'input_tokens', 'output_tokens', 'latency_ms', 'created_at'],
            Schema::getColumnListing('assistant_runs'),
        );
    }

    public function test_every_call_writes_one_row_with_metadata_only(): void
    {
        $user = User::factory()->create();
        $this->fake->push(self::reply('answer', 'Reply text that must not be stored.'), new ProviderUnavailable('down'));

        $this->ask('Secret customer question?', actor: AssistantActor::user($user->id));
        $this->ask('Second question', AssistantFeature::StudioAway, AssistantActor::user($user->id));
        $this->ask('');
        config(['assistant.enabled' => false]);
        $this->ask('Third');

        $this->assertSame(4, AssistantRun::query()->count());

        $first = AssistantRun::query()->orderBy('id')->first();
        $this->assertSame($user->id, $first->user_id);
        $this->assertNull($first->guest_key);
        $this->assertSame(AssistantFeature::AskLumi, $first->feature);
        $this->assertSame('fake', $first->provider);
        $this->assertSame('fake', $first->model);
        $this->assertSame(AssistantOutcome::Answered, $first->outcome);
        $this->assertSame(AssistantIntent::Answer, $first->intent);
        $this->assertSame(100, $first->input_tokens);
        $this->assertSame(20, $first->output_tokens);
        $this->assertNotNull($first->latency_ms);
        $this->assertNotNull($first->created_at);

        $this->assertSame(
            [AssistantOutcome::Answered, AssistantOutcome::FallbackUnavailable, AssistantOutcome::RejectedInput, AssistantOutcome::FallbackDisabled],
            AssistantRun::query()->orderBy('id')->get()->pluck('outcome')->all(),
        );
        // Only the answered call has a latency: the second never got a response from the provider, so it does not count
        $this->assertSame([true, false, false, false], AssistantRun::query()->orderBy('id')->get()->map(fn ($run) => $run->latency_ms !== null)->all());

        $stored = json_encode(AssistantRun::query()->get()->toArray());
        $this->assertStringNotContainsString('Secret customer question', $stored);
        $this->assertStringNotContainsString('must not be stored', $stored);
    }

    public function test_a_deleted_user_keeps_the_run_without_the_link(): void
    {
        $user = User::factory()->create();
        $this->ask('Hi', actor: AssistantActor::user($user->id));

        $user->delete();

        $this->assertNull(AssistantRun::query()->first()->user_id);
    }

    public function test_the_ask_command_prints_the_result_without_the_prompt(): void
    {
        $package = PhotographyPackage::factory()->published()->create(['name' => 'Wedding Classic']);
        $this->fake->push(self::reply('recommend', 'Try our wedding package.', [['type' => 'package', 'id' => $package->id]]));

        $this->artisan('assistant:ask', ['message' => 'magkano ang wedding package?'])
            ->expectsOutputToContain('Try our wedding package.')
            ->expectsOutputToContain('recommend')
            ->expectsOutputToContain('answered')
            ->expectsOutputToContain('Wedding Classic')
            ->doesntExpectOutputToContain('SHOP KNOWLEDGE')
            ->assertSuccessful();
    }

    public function test_the_ask_command_refuses_an_unknown_feature_and_production(): void
    {
        $this->artisan('assistant:ask', ['message' => 'hi', '--feature' => 'nope'])->assertFailed();

        $this->app['env'] = 'production';
        $this->artisan('assistant:ask', ['message' => 'hi'])
            ->expectsOutputToContain('does not run in production')
            ->assertFailed();

        $this->assertSame([], $this->fake->requests);
    }
}
