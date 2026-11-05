<?php

namespace Tests\Feature\Assistant;

use App\Enums\AssistantOutcome;
use App\Enums\MessageSender;
use App\Enums\OwnerPresenceMode;
use App\Models\ChatMessage;
use App\Services\Assistant\Exceptions\ProviderUnavailable;
use App\Services\Assistant\Providers\FakeProvider;
use App\Services\Chat\LumiReplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Chat\ChatHelpers;
use Tests\Support\FakesAssistant;
use Tests\TestCase;

/**
 * The "Lumi Lite" badge (Phase 7E-2a): when the backup provider wrote a reply because the main one could not, the reply
 * says so, to customers with a small pill and to the owner with a small line. The backup provider's name never reaches
 * a customer.
 */
class LumiLiteBadgeTest extends TestCase
{
    use ChatHelpers, FakesAssistant, RefreshDatabase;

    private FakeProvider $claude;

    private FakeProvider $gemini;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->claude, $this->gemini] = $this->fakeChain();
    }

    private function claudeDown(): void
    {
        $this->claude->push(new ProviderUnavailable(ProviderUnavailable::HTTP, 529));
    }

    public function test_the_result_says_which_provider_answered_and_whether_it_was_the_backup(): void
    {
        $this->claude->push(self::reply('answer', 'Claude here po.'));
        $main = $this->ask('What packages do you have?');
        $this->assertSame('anthropic', $main->provider);
        $this->assertFalse($main->viaBackup);

        $this->claudeDown();
        $this->gemini->push(self::reply('answer', 'Backup here po.'));
        $backup = $this->ask('What packages do you have?');
        $this->assertSame(AssistantOutcome::Answered, $backup->outcome);
        $this->assertSame('Backup here po.', $backup->reply);
        $this->assertSame('gemini', $backup->provider);
        $this->assertTrue($backup->viaBackup);

        // A fixed text names no provider and is never "Lite"
        $this->claudeDown();
        $this->gemini->push(new ProviderUnavailable(ProviderUnavailable::HTTP, 503));
        $fallback = $this->ask('What packages do you have?');
        $this->assertSame(AssistantOutcome::FallbackUnavailable, $fallback->outcome);
        $this->assertNull($fallback->provider);
        $this->assertFalse($fallback->viaBackup);
    }

    public function test_the_stored_message_and_the_presenter_carry_via_backup_but_never_the_provider_to_the_customer(): void
    {
        $customer = $this->customer();
        $ask = $this->askLumi($customer);

        $this->say($ask, $customer, 'What packages do you have?');
        $this->claude->push(self::reply('answer', 'Main answer.'));
        app(LumiReplyService::class)->reply($ask, $customer);

        $this->say($ask, $customer, 'And for families?');
        $this->claudeDown();
        $this->gemini->push(self::reply('answer', 'Backup answer.'));
        app(LumiReplyService::class)->reply($ask, $customer);

        $main = ChatMessage::query()->where('body', 'Main answer.')->sole();
        $backup = ChatMessage::query()->where('body', 'Backup answer.')->sole();
        $this->assertSame(['provider' => 'anthropic', 'via_backup' => false], array_intersect_key($main->meta, array_flip(['provider', 'via_backup'])));
        $this->assertSame(['provider' => 'gemini', 'via_backup' => true], array_intersect_key($backup->meta, array_flip(['provider', 'via_backup'])));

        $response = $this->actingAs($customer)->getJson(route('customer.messages.show', $ask))->assertOk();
        $messages = collect($response->json('messages'))->keyBy('body');
        $this->assertFalse($messages['Main answer.']['via_backup']);
        $this->assertTrue($messages['Backup answer.']['via_backup']);
        $this->assertFalse($messages['What packages do you have?']['via_backup'], 'Only assistant messages can be Lite');
        $this->assertStringNotContainsStringIgnoringCase('gemini', $response->getContent());
        $this->assertStringNotContainsStringIgnoringCase('anthropic', $response->getContent());
    }

    public function test_the_studio_thread_and_the_owner_see_the_same_flag(): void
    {
        $customer = $this->customer();
        $owner = $this->owner();
        $studio = $this->studio($customer);
        $this->presence(OwnerPresenceMode::Away);

        $this->say($studio, $customer, 'Do you print on canvas?');
        $this->claudeDown();
        $this->gemini->push(self::reply('answer', 'Backup answer in the studio.'));
        app(LumiReplyService::class)->reply($studio, $customer);

        $assistant = collect($this->actingAs($owner)->getJson(route('owner.messages.show', $studio))->assertOk()->json('messages'))
            ->firstWhere('sender_type', MessageSender::Assistant->value);
        $this->assertTrue($assistant['via_backup']);
    }

    public function test_the_guest_endpoint_says_via_backup_without_the_provider(): void
    {
        $this->claudeDown();
        $this->gemini->push(self::reply('answer', 'Backup for a guest.'));

        $response = $this->postJson(route('assistant.guest'), ['message' => 'What packages do you have?'])->assertOk()
            ->assertJson(['reply' => 'Backup for a guest.', 'via_backup' => true]);
        $this->assertStringNotContainsStringIgnoringCase('gemini', $response->getContent());
    }

    public function test_owner_assist_reports_the_backup(): void
    {
        $owner = $this->owner();
        $customer = $this->customer();
        $studio = $this->studio($customer);
        $this->say($studio, $customer, 'Do you print on canvas?');

        $this->claude->push(self::reply('answer', 'Main draft.'));
        $this->actingAs($owner)->postJson(route('owner.messages.assist.suggest', $studio))->assertOk()->assertJson(['text' => 'Main draft.', 'via_backup' => false]);

        $this->claudeDown();
        $this->gemini->push(self::reply('answer', "- Asks about canvas\n- Nothing answered yet"));
        $this->actingAs($owner)->postJson(route('owner.messages.assist.summarize', $studio))->assertOk()
            ->assertJson(['available' => true, 'lines' => ['Asks about canvas', 'Nothing answered yet'], 'via_backup' => true]);
    }

    public function test_the_markup_has_the_pill_with_its_label_and_accessible_text(): void
    {
        $customer = $this->customer();
        $text = 'Our backup assistant answered this because Lumi is busy. Answers may be shorter.';

        $html = $this->actingAs($customer)->get(route('customer.assistant.page'))->assertOk()->getContent();
        $this->assertStringContainsString('data-chat-lite', $html);
        $this->assertStringContainsString("row.message.sender_type === 'assistant' && row.message.via_backup === true", $html);
        $this->assertStringContainsString('title="'.$text.'"', $html);
        $this->assertStringContainsString('Lumi Lite<span class="sr-only">: '.$text.'</span>', $html);
        $this->assertStringNotContainsStringIgnoringCase('gemini', $html);

        // The floating panel on a public page uses the same rows
        $public = $this->get(route('home'))->assertOk()->getContent();
        $this->assertStringContainsString('data-chat-lite', $public);

        $owner = $this->owner();
        $studio = $this->studio($customer);
        $ownerHtml = $this->actingAs($owner)->get(route('owner.messages.show', $studio))->assertOk()->getContent();
        $this->assertStringContainsString('data-chat-lite', $ownerHtml);
        $this->assertStringContainsString('data-assist-backup', $ownerHtml);
        $this->assertStringContainsString('Written by the backup assistant because Lumi is busy.', $ownerHtml);
    }
}
