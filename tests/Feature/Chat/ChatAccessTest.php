<?php

namespace Tests\Feature\Chat;

use App\Models\ChatMessage;
use App\Models\Conversation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Who may open what (Phase 7B), the one studio thread per customer, and the tables themselves. */
class ChatAccessTest extends TestCase
{
    use ChatHelpers, RefreshDatabase;

    public function test_a_customer_never_reaches_another_customers_conversations(): void
    {
        $ana = $this->customer();
        $ben = $this->customer();
        $studio = $this->studio($ben);
        $lumi = $this->askLumi($ben);
        $this->say($studio, $ben, 'Private question');

        foreach ([$studio, $lumi] as $conversation) {
            $this->actingAs($ana)->getJson(route('customer.messages.show', $conversation))->assertNotFound()->assertJson(['code' => 'not_found']);
            $this->actingAs($ana)->postJson(route('customer.messages.store', $conversation), ['body' => 'hi'])->assertNotFound();
            $this->actingAs($ana)->postJson(route('customer.messages.lumi', $conversation))->assertNotFound();
            $this->actingAs($ana)->postJson(route('customer.messages.read', $conversation))->assertNotFound();
        }

        $this->actingAs($ana)->getJson(route('customer.assistant.handoff-draft', $lumi))->assertNotFound();
        $this->actingAs($ana)->getJson(route('customer.messages.show', 999999))->assertNotFound()->assertJson(['code' => 'not_found']);
        $this->assertSame(1, ChatMessage::query()->where('conversation_id', $studio->id)->count());
    }

    public function test_the_owner_sees_studio_threads_only_never_an_ask_lumi_conversation(): void
    {
        $owner = $this->owner();
        $customer = $this->customer();
        $lumi = $this->askLumi($customer);
        $studio = $this->studio($customer);

        $this->actingAs($owner)->getJson(route('owner.messages.show', $lumi))->assertNotFound();
        $this->actingAs($owner)->postJson(route('owner.messages.store', $lumi), ['body' => 'hi'])->assertNotFound();
        $this->actingAs($owner)->postJson(route('owner.messages.read', $lumi))->assertNotFound();
        $this->actingAs($owner)->postJson(route('owner.messages.resolve', $lumi))->assertNotFound();
        $this->actingAs($owner)->getJson(route('owner.messages.show', $studio))->assertOk();
    }

    public function test_roles_and_guests(): void
    {
        $customer = $this->customer();
        $owner = $this->owner();
        $studio = $this->studio($customer);

        $this->actingAs($customer)->getJson(route('owner.messages.index'))->assertForbidden()->assertJson(['code' => 'forbidden']);
        $this->actingAs($customer)->getJson(route('owner.messages.presence'))->assertForbidden();
        $this->actingAs($customer)->postJson(route('owner.messages.presence.update'), ['mode' => 'away'])->assertForbidden();
        $this->actingAs($owner)->getJson(route('customer.messages.studio'))->assertForbidden();
        $this->actingAs($owner)->postJson(route('customer.assistant.store'))->assertForbidden();

        auth()->logout();
        $this->get(route('customer.messages.studio'))->assertRedirect(route('login'));
        $this->get(route('owner.messages.index'))->assertRedirect(route('login'));
        $this->post(route('customer.messages.store', $studio), ['body' => 'hi'])->assertRedirect(route('login'));
    }

    public function test_exactly_one_studio_thread_per_customer_created_on_first_open(): void
    {
        $customer = $this->customer();

        $first = $this->actingAs($customer)->getJson(route('customer.messages.studio'))->assertOk()->json('conversation.id');
        $second = $this->actingAs($customer)->getJson(route('customer.messages.studio'))->assertOk()->json('conversation.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Conversation::query()->studio()->forCustomer($customer)->count());
    }

    public function test_a_studio_thread_created_by_another_request_at_the_same_moment_is_reused(): void
    {
        $customer = $this->customer();

        // Another request inserts the studio row between our "not found" and our insert
        Conversation::creating(function (Conversation $conversation) use ($customer) {
            Conversation::flushEventListeners();
            DB::table('conversations')->insert(['customer_id' => $customer->id, 'kind' => 'studio', 'thread_key' => 'studio', 'created_at' => now(), 'updated_at' => now()]);
        });

        $conversation = $this->studio($customer);

        $this->assertSame(1, Conversation::query()->studio()->forCustomer($customer)->count());
        $this->assertSame(Conversation::query()->studio()->forCustomer($customer)->value('id'), $conversation->id);
    }

    public function test_the_database_refuses_a_second_studio_thread(): void
    {
        $customer = $this->customer();
        $this->studio($customer);

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('conversations')->insert(['customer_id' => $customer->id, 'kind' => 'studio', 'thread_key' => 'studio']);
    }

    public function test_many_ask_lumi_conversations_each_open_with_lumis_introduction(): void
    {
        $customer = $this->customer();

        $response = $this->actingAs($customer)->postJson(route('customer.assistant.store'))->assertCreated();
        $this->actingAs($customer)->postJson(route('customer.assistant.store'))->assertCreated();

        $this->assertSame(2, Conversation::query()->assistant()->forCustomer($customer)->count());
        $response->assertJsonPath('conversation.kind', 'assistant')
            ->assertJsonPath('messages.0.sender_type', 'assistant')
            ->assertJsonPath('messages.0.sender_label', 'Lumi (AI)')
            ->assertJsonPath('messages.0.system_kind', 'lumi_intro')
            ->assertJsonPath('messages.0.body', config('chat.messages.lumi_intro'))
            ->assertJsonPath('state.studio', null);
        $this->assertDatabaseCount('assistant_runs', 0);

        $this->actingAs($customer)->getJson(route('customer.assistant.index'))->assertOk()->assertJsonCount(2, 'conversations');
    }

    public function test_deleting_a_customer_removes_their_conversations_and_messages(): void
    {
        $customer = $this->customer();
        $this->say($this->studio($customer), $customer);
        $this->askLumi($customer);

        $customer->delete();

        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('chat_messages', 0);
    }
}
