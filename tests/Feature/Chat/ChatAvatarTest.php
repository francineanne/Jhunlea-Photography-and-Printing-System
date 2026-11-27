<?php

namespace Tests\Feature\Chat;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/** Phase 10F: Lumi, the studio and the customer never share a face in the chat. */
class ChatAvatarTest extends TestCase
{
    use ChatHelpers, RefreshDatabase;

    public function test_lumi_and_the_studio_have_different_avatars(): void
    {
        $lumi = Blade::render('<x-chat.avatar who="lumi" />');
        $studio = Blade::render('<x-chat.avatar who="studio" />');

        // Lumi: its own sparkle on the violet tint, with the AI badge, never the shop's camera mark
        $this->assertStringContainsString('data-chat-avatar="lumi"', $lumi);
        $this->assertStringContainsString('bg-status-violet-bg', $lumi);
        $this->assertStringContainsString('>AI<', $lumi);
        $this->assertStringNotContainsString('>camera<', $lumi);
        $this->assertStringContainsString('title="Lumi, AI assistant"', $lumi);

        // The studio: the camera mark in a neutral round frame, no AI badge
        $this->assertStringContainsString('data-chat-avatar="studio"', $studio);
        $this->assertStringContainsString('>camera<', $studio);
        $this->assertStringContainsString('rounded-full border', $studio);
        $this->assertStringNotContainsString('>AI<', $studio);
        $this->assertStringNotContainsString('bg-brand text-white', $studio);
    }

    public function test_the_inbox_rows_and_the_thread_header_show_the_customers_own_face(): void
    {
        $owner = $this->owner();
        $ana = $this->customer(['name' => 'Ana Reyes']);
        $ana->forceFill(['avatar_path' => 'avatars/ana.png'])->save();
        $ben = $this->customer(['name' => 'Ben Cruz']);
        $this->say($this->studio($ana), $ana, 'Hello');
        $benThread = $this->studio($ben);
        $this->say($benThread, $ben, 'Hi');

        $rows = collect($this->actingAs($owner)->getJson(route('owner.messages.index'))->assertOk()->json('conversations'))->keyBy('customer.name');
        $this->assertSame('/storage/avatars/ana.png', $rows['Ana Reyes']['customer']['avatar']);
        $this->assertNull($rows['Ben Cruz']['customer']['avatar']);
        $this->assertSame('BC', $rows['Ben Cruz']['customer']['initials']);
        // Never an email or a phone in the row
        $this->assertSame(['name', 'avatar', 'initials'], array_keys($rows['Ana Reyes']['customer']));

        $this->actingAs($owner)->get(route('owner.messages.show', $benThread))->assertOk()
            ->assertSee('data-user-avatar', false)->assertSee('title="Ben Cruz"', false)->assertSee('data-inbox-avatar', false);
    }

    public function test_the_owner_is_named_with_studio_and_messages_carry_the_right_avatar(): void
    {
        $owner = $this->owner(['name' => 'Jhun']);
        $customer = $this->customer(['name' => 'Ana Reyes']);
        $thread = $this->studio($customer);
        $this->say($thread, $customer, 'Question');
        $this->ownerSays($thread, $owner, 'Answer');

        $messages = collect($this->actingAs($customer)->getJson(route('customer.messages.show', $thread))->json('messages'))->keyBy('body');
        $this->assertSame('Jhun', $messages['Answer']['sender_label']);
        $this->assertSame('owner', $messages['Answer']['sender_type']);
        $this->assertSame('AR', $messages['Question']['avatar']['initials']);

        $page = $this->actingAs($customer)->get(route('customer.assistant.page'))->assertOk();
        $page->assertSee('data-chat-studio-tag', false)->assertSee('· Studio');
    }

    /** Phase 10Q: the thread JSON (through ThreadReader) carries the pictures, not only the initials, on both sides. */
    public function test_the_thread_json_carries_the_customers_and_the_owners_picture_on_both_sides(): void
    {
        $owner = $this->owner(['name' => 'Jhun']);
        $owner->forceFill(['avatar_path' => 'avatars/owner.png'])->save();
        $ana = $this->customer(['name' => 'Ana Morales']);
        $ana->forceFill(['avatar_path' => 'avatars/ana.png'])->save();
        $ben = $this->customer(['name' => 'Ben Cruz']);

        $thread = $this->studio($ana);
        $this->say($thread, $ana, 'From Ana');
        $this->ownerSays($thread, $owner, 'From the studio');
        $benThread = $this->studio($ben);
        $this->say($benThread, $ben, 'From Ben');

        foreach ([
            [$owner, route('owner.messages.show', $thread)],
            [$ana, route('customer.messages.show', $thread)],
        ] as [$viewer, $url]) {
            $messages = collect($this->actingAs($viewer)->getJson($url)->assertOk()->json('messages'))->keyBy('body');
            $this->assertSame('/storage/avatars/ana.png', $messages['From Ana']['avatar']['url']);
            $this->assertSame('AM', $messages['From Ana']['avatar']['initials']);
            $this->assertSame('/storage/avatars/owner.png', $messages['From the studio']['avatar']['url']);
        }

        // A customer without a picture still gets the initials and no url
        $messages = collect($this->actingAs($owner)->getJson(route('owner.messages.show', $benThread))->assertOk()->json('messages'))->keyBy('body');
        $this->assertSame(['url' => null, 'initials' => 'BC'], $messages['From Ben']['avatar']);
    }
}
