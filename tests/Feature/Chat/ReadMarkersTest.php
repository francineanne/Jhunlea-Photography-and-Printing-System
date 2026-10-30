<?php

namespace Tests\Feature\Chat;

use App\Enums\NotificationKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Tests\TestCase;

/** Unread counts and read markers on both sides (Phase 7B). */
class ReadMarkersTest extends TestCase
{
    use ChatHelpers, RefreshDatabase;

    public function test_unread_counts_for_both_sides_and_reading_clears_them(): void
    {
        $customer = $this->customer();
        $owner = $this->owner();
        $studio = $this->studio($customer);

        $this->say($studio, $customer, 'One');
        $this->say($studio, $customer, 'Two');

        $this->actingAs($owner)->getJson(route('owner.messages.show', $studio))->assertJsonPath('state.unread_count', 2);
        $this->actingAs($customer)->getJson(route('customer.messages.show', $studio))->assertJsonPath('state.unread_count', 0);

        $this->actingAs($owner)->postJson(route('owner.messages.read', $studio))->assertOk()->assertJsonPath('state.unread_count', 0);

        // The owner's reply is unread for the customer until they read it
        $this->ownerSays($studio, $owner, 'Hello!');
        $this->actingAs($customer)->getJson(route('customer.messages.show', $studio))->assertJsonPath('state.unread_count', 1);
        $this->actingAs($customer)->postJson(route('customer.messages.read', $studio))->assertOk()->assertJsonPath('state.unread_count', 0);
    }

    public function test_the_read_marker_never_moves_backward(): void
    {
        $customer = $this->customer();
        $owner = $this->owner();
        $studio = $this->studio($customer);
        $first = $this->say($studio, $customer, 'One');
        $second = $this->say($studio, $customer, 'Two');

        $this->actingAs($owner)->postJson(route('owner.messages.read', $studio), ['up_to' => $second->id])->assertOk();
        $this->actingAs($owner)->postJson(route('owner.messages.read', $studio), ['up_to' => $first->id])->assertOk();
        $this->assertSame($second->id, $studio->refresh()->owner_last_read_message_id);

        // An id beyond the thread stops at its newest message
        $this->actingAs($owner)->postJson(route('owner.messages.read', $studio), ['up_to' => $second->id + 1000])->assertOk();
        $this->assertSame($second->id, $studio->refresh()->owner_last_read_message_id);
    }

    public function test_reading_a_thread_marks_its_message_notifications_read(): void
    {
        $customer = $this->customer();
        $other = $this->customer();
        $owner = $this->owner();
        $studio = $this->studio($customer);
        $this->say($studio, $customer, 'Hello');
        $this->say($this->studio($other), $other, 'Another thread');

        $this->assertSame(2, $owner->unreadNotifications()->count());

        $this->actingAs($owner)->postJson(route('owner.messages.read', $studio))->assertOk();

        $unread = $owner->unreadNotifications()->get();
        $this->assertCount(1, $unread);
        $this->assertSame($this->studio($other)->id, $unread->first()->data['conversation_id']);
        $this->assertSame(NotificationKind::MessageReceived->value, DatabaseNotification::query()->whereNotNull('read_at')->value('type'));
    }
}
