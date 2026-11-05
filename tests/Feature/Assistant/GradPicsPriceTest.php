<?php

namespace Tests\Feature\Assistant;

use App\Enums\AssistantFeature;
use App\Models\PhotographyPackage;
use App\Services\Assistant\AssistantActor;
use App\Services\Assistant\AssistantEngine;
use App\Services\Assistant\AssistantInput;
use App\Services\Assistant\ClaimGuard;
use App\Services\Assistant\CustomerRecords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The "grad pics" finding of the second real eval (Phase 7E-2d): "magkano grad pics nyo?" got a reply that began with
 * "Kasama dito ..." because its first sentence, which named the package and its price, was removed. The cause was the
 * 7E-2c owner-promise rule of ClaimGuard: "kukumpirmahin ng owner ang final na presyo" (the owner will confirm the final
 * price) was read as a booking promise, since the price word came more than three words after the verb. PriceGuard was
 * never the cause: it reads every format of the shop's own price.
 */
class GradPicsPriceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['assistant.provider' => 'anthropic', 'assistant.fallback_provider' => null, 'assistant.anthropic.api_key' => 'sk-ant-test-dummy-not-a-key',
            'assistant.anthropic.model' => 'claude-haiku-5-5', 'assistant.enabled' => true, 'assistant.code_answers.enabled' => true,
            'assistant.limits.per_minute_per_user' => 100]);

        // The seeded sample package: from ₱1,850.00, final price confirmed after review
        PhotographyPackage::factory()->published()->create(['name' => 'Graduation Portraits', 'category' => 'Graduation', 'price' => '1850.00', 'price_requires_confirmation' => true]);
    }

    private string $next = '';

    private bool $faked = false;

    /** The scripted Claude reply for the next call (one Http::fake, since repeated fakes stack and the first one wins). */
    private function claude(string $reply): void
    {
        $this->next = $reply;

        if (! $this->faked) {
            $this->faked = true;
            Http::fake(['api.anthropic.com/*' => fn () => Http::response([
                'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-5-5', 'stop_reason' => 'tool_use',
                'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'reply', 'input' => ['intent' => 'answer', 'reply' => $this->next, 'recommend' => []]]],
                'usage' => ['input_tokens' => 2000, 'output_tokens' => 80],
            ])]);
        }
    }

    private function ask(string $message): string
    {
        return app(AssistantEngine::class)->handle(new AssistantInput(
            AssistantFeature::AskLumi, AssistantActor::guest('198.51.100.70'), $message))->reply;
    }

    public function test_the_exact_scenario_keeps_the_sentence_with_the_package_and_its_price(): void
    {
        $reply = 'Ang Graduation Portraits package namin ay nagsisimula sa ₱1,850.00, at kukumpirmahin ng owner ang final na presyo pagkatapos ng review. '
            .'Kasama dito ang 60 minuto sa studio, formal at casual poses, 10 edited digital photos, at online gallery.';
        $this->claude($reply);

        $this->assertSame($reply, $this->ask('magkano grad pics nyo?'));
    }

    public function test_every_format_of_the_shops_own_price_is_kept_and_an_invented_one_is_removed(): void
    {
        foreach (['₱1,850', '₱1,850.00', 'P1850', 'Php 1,850', '1,850 pesos', 'from ₱1,850', 'PHP1,850'] as $amount) {
            $this->claude("The Graduation Portraits package is {$amount}. It includes 10 edited photos.");
            $this->assertSame("The Graduation Portraits package is {$amount}. It includes 10 edited photos.", $this->ask('magkano grad pics nyo?'), $amount);
        }

        $this->claude('The Graduation Portraits package is ₱1,500 this week only. It includes 10 edited photos.');
        $this->assertSame('It includes 10 edited photos.', $this->ask('magkano grad pics nyo?'), 'An amount the shop never gave is still removed');
    }

    public function test_price_confirmations_are_not_booking_promises(): void
    {
        $guard = app(ClaimGuard::class);
        $none = CustomerRecords::none();

        foreach ([
            'Ang Graduation Portraits package ay nagsisimula sa ₱1,850.00, at kukumpirmahin ng owner ang final na presyo pagkatapos ng review.',
            'Ang Graduation Portraits ay mula ₱1,850.00; kukumpirmahin pa ng owner ang huling presyo.',
            'Ang presyo ay kukumpirmahin ng owner.',
            'The owner will confirm the exact final price after review.',
            'The studio will confirm your total amount after checking the files.',
        ] as $sentence) {
            $this->assertFalse($guard->claims($sentence, $none), $sentence);
        }
    }

    public function test_booking_promises_are_still_removed(): void
    {
        $guard = app(ClaimGuard::class);
        $none = CustomerRecords::none();

        foreach ([
            'Kukumpirmahin ng owner ang booking ninyo.',
            'Kukumpirmahin ng owner ang booking at presyo ninyo.',
            'Ang presyo ay ₱1,850, at kukumpirmahin ng owner ang booking mo.',
            'The owner will confirm your booking and the price.',
            'The owner will confirm it there.',
            'Your booking will be confirmed by the owner.',
        ] as $sentence) {
            $this->assertTrue($guard->claims($sentence, $none), $sentence);
        }
    }
}
