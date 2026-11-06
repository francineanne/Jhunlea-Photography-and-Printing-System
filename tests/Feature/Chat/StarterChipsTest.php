<?php

namespace Tests\Feature\Chat;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The suggested questions of an empty Ask Lumi window (Phase 7E-2a): four tappable chips from config that send their
 * text as the customer's message and hide once the customer has written. A guest, who has no orders, gets a printing
 * question instead of "Where is my order?".
 */
class StarterChipsTest extends TestCase
{
    use ChatHelpers, RefreshDatabase;

    public function test_a_customer_sees_the_four_configured_questions(): void
    {
        $this->assertSame(['What photography packages do you offer?', 'Where is my order?', 'How do I book a photoshoot?', 'What are your opening hours?'], config('chat.suggested_prompts'));

        $html = $this->actingAs($this->customer())->get(route('customer.assistant.page'))->assertOk()->getContent();

        $this->assertStringContainsString('x-show="showPrompts"', $html);
        $this->assertSame(4, substr_count($html, 'data-chat-prompt '));
        foreach (config('chat.suggested_prompts') as $prompt) {
            // Tapping puts the text in the Ask Lumi draft and sends it, like a typed message
            $this->assertStringContainsString("x-on:click=\"drafts.ask = '{$prompt}'; submit()\"", $html);
        }
    }

    public function test_a_guest_gets_the_guest_list_without_the_order_question(): void
    {
        $html = $this->get(route('home'))->assertOk()->getContent();

        $this->assertSame(4, substr_count($html, 'data-chat-prompt '));
        $this->assertStringContainsString('What printing services do you offer?', $html);
        $this->assertStringNotContainsString('Where is my order?', $html);
    }

    public function test_the_owner_panel_shows_four_chips_and_the_full_page_five(): void
    {
        $owner = User::factory()->owner()->create();
        $prompts = config('assistant.owner.suggested_prompts');

        // The floating panel (every owner page but its own): at most 4, in order; the full page: up to 5
        $panel = $this->actingAs($owner)->get(route('owner.dashboard'))->assertOk()->getContent();
        $this->assertSame(min(4, count($prompts)), substr_count($panel, 'data-owner-ask-chip '));
        $this->assertStringContainsString("x-on:click=\"ask('{$prompts[0]}')\"", $panel);

        $page = $this->actingAs($owner)->get(route('owner.assistant.index'))->assertOk()->getContent();
        $this->assertSame(min(5, count($prompts)), substr_count($page, 'data-owner-ask-chip '));
    }

    public function test_the_chips_hide_once_the_customer_has_written(): void
    {
        // The Alpine getter that shows them: the Ask tab, loaded, and no customer message yet
        $js = file_get_contents(resource_path('js/chat.js'));

        $this->assertStringContainsString("return this.tab === 'ask' && this.thread.loaded && !this.thread.messages.some((message) => message.sender_type === 'customer');", $js);
    }
}
