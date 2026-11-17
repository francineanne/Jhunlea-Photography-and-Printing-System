<?php

namespace Tests\Feature\Chat;

use App\Enums\MessageSender;
use App\Services\Chat\MessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Phase 10E: the chat feels like a chat app: "Seen" from human reads only, polling for newer messages only, no blanks. */
class ChatPolishTest extends TestCase
{
    use ChatHelpers, RefreshDatabase;

    public function test_seen_follows_only_the_other_persons_own_read_marker(): void
    {
        $customer = $this->customer();
        $owner = $this->owner();
        $studio = $this->studio($customer);
        $mine = $this->say($studio, $customer, 'Is my order ready?');

        // Lumi answering (away owner) never counts as the studio having seen it
        app(MessageService::class)->insert($studio, MessageSender::Assistant, null, 'Lumi here, the studio will check.', ['intent' => 'answer']);
        $this->actingAs($customer)->getJson(route('customer.messages.show', $studio))->assertJsonPath('state.seen_up_to', 0);

        // The owner opening the thread does
        $this->actingAs($owner)->postJson(route('owner.messages.read', $studio), [])->assertOk();
        $seen = $this->actingAs($customer)->getJson(route('customer.messages.show', $studio))->json('state.seen_up_to');
        $this->assertGreaterThanOrEqual($mine->id, $seen);

        // And the owner sees the customer's marker the same way
        $reply = $this->ownerSays($studio, $owner, 'Yes, it is ready.');
        $this->actingAs($owner)->getJson(route('owner.messages.show', $studio))->assertJsonPath('state.seen_up_to', 0);
        $this->actingAs($customer)->postJson(route('customer.messages.read', $studio), [])->assertOk();
        $this->assertGreaterThanOrEqual($reply->id, $this->actingAs($owner)->getJson(route('owner.messages.show', $studio))->json('state.seen_up_to'));
    }

    public function test_polling_returns_only_newer_messages_of_the_callers_own_thread(): void
    {
        $ana = $this->customer();
        $ben = $this->customer();
        $anaThread = $this->studio($ana);
        $benThread = $this->studio($ben);
        $first = $this->say($anaThread, $ana, 'First');
        $this->say($benThread, $ben, 'Ben writes');
        $second = $this->say($anaThread, $ana, 'Second');

        $poll = $this->actingAs($ana)->getJson(route('customer.messages.show', [$anaThread, 'after' => $first->id]))->assertOk();
        $this->assertSame([$second->id], array_column($poll->json('messages'), 'id'));

        // Another customer's thread answers 404, whatever is asked
        $this->actingAs($ana)->getJson(route('customer.messages.show', [$benThread, 'after' => 0]))->assertNotFound();
    }

    public function test_a_message_of_only_spaces_is_refused_and_a_retry_is_stored_once(): void
    {
        $customer = $this->customer();
        $studio = $this->studio($customer);

        $this->actingAs($customer)->postJson(route('customer.messages.store', $studio), ['body' => "   \n  ", 'client_id' => 'c-1'])->assertStatus(422);
        $this->assertSame(0, $studio->messages()->count());

        $this->actingAs($customer)->postJson(route('customer.messages.store', $studio), ['body' => 'Check https://example.test/a <b>bold</b>', 'client_id' => 'c-2'])->assertCreated();
        $again = $this->actingAs($customer)->postJson(route('customer.messages.store', $studio), ['body' => 'Check https://example.test/a <b>bold</b>', 'client_id' => 'c-2'])->assertOk();

        $this->assertSame(1, $studio->messages()->count());
        // Stored and returned as plain text; the page escapes it before turning https addresses into links
        $this->assertSame('Check https://example.test/a <b>bold</b>', $again->json('message.body'));
    }

    public function test_the_chat_templates_render_bodies_through_the_safe_link_helper(): void
    {
        $rows = file_get_contents(resource_path('views/components/chat/rows.blade.php'));
        $common = file_get_contents(resource_path('js/chat-common.js'));

        $this->assertStringContainsString('x-html="$linkify(row.message.body)"', $rows);
        // Escaped first, https only, new tab, no opener
        $this->assertStringContainsString('escapeHtml(text).replace(/https:\\/\\/', $common);
        $this->assertStringContainsString('rel="noopener noreferrer nofollow"', $common);
        $this->assertStringNotContainsString('http:\\/\\/', $common);
    }
}
