<?php

namespace Tests\Feature\Chat;

use App\Enums\MessageSender;
use App\Enums\NotificationKind;
use App\Models\User;
use App\Services\Chat\LumiReplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Tests\Support\FakesAssistant;
use Tests\TestCase;

/** The owner's replies and the chat notifications (Phase 7B). */
class OwnerReplyAndNoticesTest extends TestCase
{
    use ChatHelpers, FakesAssistant, RefreshDatabase;

    /** @return list<string> */
    private function kindsFor(User $user): array
    {
        return DatabaseNotification::query()->where('notifiable_id', $user->id)->orderBy('created_at')->pluck('type')->all();
    }

    public function test_an_owner_reply_is_stored_clears_needs_owner_and_tells_the_customer(): void
    {
        $customer = $this->customer();
        $owner = $this->owner(['name' => 'Jhun']);
        $studio = $this->studio($customer);
        $this->say($studio, $customer, 'Hello');
        $studio->forceFill(['needs_owner' => true, 'needs_owner_at' => now()])->save();

        $this->actingAs($owner)->postJson(route('owner.messages.store', $studio), ['body' => 'Hi! How can I help?', 'client_id' => 'o-1'])
            ->assertCreated()
            ->assertJsonPath('message.sender_type', 'owner')
            ->assertJsonPath('message.sender_label', 'Jhun')
            ->assertJsonPath('message.mine', true)
            ->assertJsonPath('state.studio.needs_owner', false);

        $studio->refresh();
        $this->assertFalse($studio->needs_owner);
        $this->assertNull($studio->needs_owner_at);
        $this->assertSame([NotificationKind::MessageReplied->value], $this->kindsFor($customer));

        // The same client_id again: one row, no second notice
        $this->actingAs($owner)->postJson(route('owner.messages.store', $studio), ['body' => 'Hi! How can I help?', 'client_id' => 'o-1'])->assertOk();
        $this->assertSame(1, $studio->messages()->where('sender_type', MessageSender::Owner)->count());
    }

    public function test_the_owner_taking_over_from_lumi_is_announced(): void
    {
        $this->fakeAssistant();
        $customer = $this->customer();
        $owner = $this->owner();
        $studio = $this->studio($customer);
        $this->say($studio, $customer, 'Hello?');
        app(LumiReplyService::class)->reply($studio, $customer);

        $this->ownerSays($studio, $owner, 'I am here now.');

        $last = $studio->messages()->orderByDesc('id')->limit(2)->get()->reverse()->values();
        $this->assertSame([MessageSender::System, MessageSender::Owner], $last->pluck('sender_type')->all());
        $this->assertSame('owner_joined', $last[0]->systemKind()?->value);
        $this->assertSame(config('chat.messages.handoff_to_owner'), $last[0]->body);

        // Not again while the owner is the one answering
        $this->say($studio, $customer, 'Great');
        $this->ownerSays($studio, $owner, 'Yes?');
        $this->assertSame(1, $studio->messages()->where('sender_type', MessageSender::System)->get()->filter(fn ($message) => $message->systemKind()?->value === 'owner_joined')->count());
    }

    public function test_resolve_clears_needs_owner_without_a_message(): void
    {
        $customer = $this->customer();
        $owner = $this->owner();
        $studio = $this->studio($customer);
        $this->say($studio, $customer);
        $studio->forceFill(['needs_owner' => true, 'needs_owner_at' => now()])->save();

        $this->actingAs($owner)->postJson(route('owner.messages.resolve', $studio))->assertOk()->assertJsonPath('state.studio.needs_owner', false);

        $this->assertFalse($studio->refresh()->needs_owner);
        $this->assertSame(1, $studio->messages()->count());
        $this->actingAs($customer)->postJson('/owner/messages/'.$studio->id.'/resolve')->assertForbidden();
    }

    public function test_owners_are_told_once_until_they_read_it(): void
    {
        $customer = $this->customer(['name' => 'Ana Reyes']);
        $owner = $this->owner();
        $secondOwner = $this->owner();
        $studio = $this->studio($customer);

        $this->say($studio, $customer, 'One');
        $this->say($studio, $customer, 'Two');

        $this->assertSame([NotificationKind::MessageReceived->value], $this->kindsFor($owner));
        $this->assertSame([NotificationKind::MessageReceived->value], $this->kindsFor($secondOwner));

        $owner->unreadNotifications()->update(['read_at' => now()]);
        $this->say($studio, $customer, 'Three');

        $this->assertCount(2, $this->kindsFor($owner));
        $this->assertCount(1, $this->kindsFor($secondOwner), 'The second owner still has the first one unread');

        // Ask Lumi messages never reach the owner
        $this->say($this->askLumi($customer), $customer, 'Private question for Lumi');
        $this->assertCount(2, $this->kindsFor($owner));
    }

    public function test_the_customer_is_told_once_until_they_read_it(): void
    {
        $customer = $this->customer();
        $owner = $this->owner();
        $studio = $this->studio($customer);
        $this->say($studio, $customer);

        $this->ownerSays($studio, $owner, 'Reply one');
        $this->ownerSays($studio, $owner, 'Reply two');
        $this->assertSame([NotificationKind::MessageReplied->value], $this->kindsFor($customer));

        $this->actingAs($customer)->postJson(route('customer.messages.read', $studio))->assertOk();
        $this->assertSame(0, $customer->unreadNotifications()->count());

        $this->ownerSays($studio, $owner, 'Reply three');
        $this->assertCount(2, $this->kindsFor($customer));
    }

    public function test_notification_data_holds_no_message_text(): void
    {
        $customer = $this->customer(['name' => 'Ana Reyes', 'email' => 'ana@example.test']);
        $owner = $this->owner();
        $studio = $this->studio($customer);
        $this->say($studio, $customer, 'My secret question about prices');
        $this->ownerSays($studio, $owner, 'The secret answer');

        foreach (DatabaseNotification::query()->get() as $row) {
            $text = json_encode($row->data).' '.NotificationKind::from($row->type)->message($row->data);
            $this->assertStringNotContainsString('secret', $text);
            $this->assertStringNotContainsString('ana@example.test', $text);
            $this->assertSame(['kind', 'conversation_id', 'sender_name'], array_keys($row->data));
        }

        $this->assertSame('New message from Ana Reyes.', NotificationKind::MessageReceived->message($owner->notifications()->first()->data));
        $this->assertSame('The studio replied to your message.', NotificationKind::MessageReplied->message($customer->notifications()->first()->data));
    }

    public function test_the_bell_and_the_notifications_page_show_the_new_kinds_and_link_to_the_thread(): void
    {
        $customer = $this->customer(['name' => 'Ana Reyes']);
        $owner = $this->owner();
        $studio = $this->studio($customer);
        $this->say($studio, $customer, 'Hello');
        $this->ownerSays($studio, $owner, 'Hi');

        $this->actingAs($owner)->get(route('owner.notifications.index'))->assertOk()->assertSee('New message')->assertSee('New message from Ana Reyes.');
        $this->actingAs($owner)->get(route('owner.dashboard'))->assertOk()->assertSee('New message from Ana Reyes.');
        $this->actingAs($customer)->get(route('customer.notifications.index'))->assertOk()->assertSee('The studio replied')->assertSee('The studio replied to your message.');

        $row = $owner->notifications()->first();
        $this->actingAs($owner)->post(route('owner.notifications.open', $row->id))->assertRedirect(route('owner.messages.show', $studio));
        $this->assertNotNull($row->fresh()->read_at);

        $customerRow = $customer->notifications()->first();
        $this->actingAs($customer)->get(route('customer.notifications.go', $customerRow->id))->assertRedirect(route('customer.messages.show', $studio));
    }
}
