<?php

namespace Tests\Feature\Chat;

use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Services\Chat\OwnerPresence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Phase 7F: who the customer talks to. The launcher says "Chat", a chooser picks Lumi (AI) or the studio (a person),
 * "Message the studio" buttons open the Studio tab with a typed draft of the customer's own reference, the Studio tab
 * shows a coarse presence line, and the brand mark is the avatar of both (the sparkle icon is gone).
 */
class ChatEntryTest extends TestCase
{
    use ChatHelpers, RefreshDatabase;

    /** @return array<string, mixed> */
    private function chatConfig(TestResponse $response): array
    {
        $this->assertSame(1, preg_match("/x-data=\"chatWindow\\(JSON\\.parse\\('(.+?)'\\)\\)\"/", $response->getContent(), $match));

        return json_decode(json_decode('"'.$match[1].'"', flags: JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_the_launcher_says_chat_with_lumi_or_the_studio(): void
    {
        $name = config('assistant.name');

        foreach ([$this->get(route('home')), $this->actingAs($this->customer())->get(route('customer.dashboard'))] as $page) {
            $page->assertOk()
                ->assertSee('aria-label="Chat with '.$name.' or the studio"', false)
                ->assertSee('>Chat</span>', false)
                ->assertSee($name.' AI or the studio')
                ->assertDontSee('Open chat with');
        }

        // The owner's own launcher keeps its name
        $this->actingAs($this->owner())->get(route('owner.dashboard'))->assertOk()
            ->assertSee('Open Ask '.$name, false)
            ->assertDontSee('data-chat-launcher', false);
    }

    public function test_the_chooser_and_the_avatars_name_lumi_and_the_studio_by_text(): void
    {
        $name = config('assistant.name');

        $page = $this->actingAs($this->customer())->get(route('customer.dashboard'))->assertOk()
            ->assertSee('Who do you want to talk to?')
            ->assertSee('Ask '.$name.' (AI)')
            ->assertSee('Message the studio (a person)')
            ->assertSee('aria-label="'.$name.', AI assistant"', false)
            ->assertSee('aria-label="Studio"', false)
            ->assertSee('data-chat-switch', false)
            ->assertSee('Changes or special requests?', false)
            ->assertSee('data-chat-more', false);

        // The AI notice is said once (the dismissible notice), never again as a second line
        $this->assertSame(1, substr_count($page->getContent(), $name.' is an AI'));

        // No tab asked for: the chooser (or the remembered choice) decides
        $this->assertFalse($this->chatConfig($page)['chosen']);
        $this->assertTrue($this->chatConfig($this->actingAs($this->customer())->get(route('customer.assistant.page', ['tab' => 'studio'])))['chosen']);
    }

    public function test_a_guest_only_asks_lumi_and_must_log_in_or_create_an_account_for_the_studio(): void
    {
        $page = $this->get(route('home'))->assertOk()
            ->assertSee('Who do you want to talk to?')
            ->assertSee('Log in or create an account first.')
            ->assertSee(route('register'), false)
            ->assertDontSee('data-message-studio', false);

        $config = $this->chatConfig($page);
        $this->assertTrue($config['guest']);
        $this->assertSame('ask', $config['tab']);
        $this->assertNull($config['draft']);
        $this->assertSame(['guest' => route('assistant.guest')], $config['urls']);
    }

    public function test_the_dashboard_button_opens_the_studio_with_no_reference(): void
    {
        $customer = $this->customer();
        PrintingOrder::factory()->forCustomer($customer)->create(['reference_number' => 'PR-000777']);

        $html = $this->actingAs($customer)->get(route('customer.dashboard'))->assertOk()->assertSee('data-message-studio', false)->getContent();

        $this->assertStringContainsString('chat:studio', $html);
        $this->assertStringNotContainsString('Hi, about', $html);

        $this->actingAs($this->owner())->get(route('customer.dashboard'))->assertForbidden();
        auth()->logout();
        $this->get(route('customer.dashboard'))->assertRedirect(route('login'));
    }

    public function test_the_order_and_booking_buttons_draft_only_the_customers_own_reference(): void
    {
        $customer = $this->customer();
        $order = PrintingOrder::factory()->forCustomer($customer)->create(['reference_number' => 'PR-000777']);
        $booking = PhotographyBooking::factory()->forCustomer($customer)->create(['reference_number' => 'PB-000888']);
        $other = $this->customer();
        $foreign = PrintingOrder::factory()->forCustomer($other)->create(['reference_number' => 'PR-000999']);

        $this->actingAs($customer)->get(route('customer.printing.show', $order))->assertOk()
            ->assertSee('data-message-studio', false)
            ->assertSee('Hi, about PR-000777: ', false)
            ->assertDontSee('PR-000999');
        $this->actingAs($customer)->get(route('customer.bookings.show', $booking))->assertOk()
            ->assertSee('Hi, about PB-000888: ', false);

        // Another customer's record never opens, so its draft never renders
        $this->assertContains($this->actingAs($customer)->get(route('customer.printing.show', $foreign))->status(), [403, 404]);

        // The full page (no JavaScript) types the same draft for the customer's own record only
        $this->assertSame('Hi, about PR-000777: ', $this->chatConfig($this->actingAs($customer)->get(route('customer.assistant.page', ['tab' => 'studio', 'about' => 'printing_order:'.$order->id])))['draft']);
        $this->assertNull($this->chatConfig($this->actingAs($customer)->get(route('customer.assistant.page', ['tab' => 'studio', 'about' => 'printing_order:'.$foreign->id])))['draft']);
    }

    public function test_the_presence_the_customer_sees_is_coarse_and_carries_no_time(): void
    {
        $customer = $this->customer();
        $studio = $this->studio($customer);
        $this->say($studio, $customer);

        foreach ([false, true] as $away) {
            $away ? $this->travel(30)->minutes() : app(OwnerPresence::class)->touch();

            $state = $this->actingAs($customer)->getJson(route('customer.messages.show', $studio))->assertOk()->json('state.studio');

            $this->assertSame(['owner_away', 'away_notice', 'lumi_active', 'waiting_for_studio', 'needs_owner'], array_keys($state));
            $this->assertSame($away, $state['owner_away']);

            $json = json_encode($state);
            $this->assertDoesNotMatchRegularExpression('/\d{4}-\d{2}-\d{2}|T\d{2}:\d{2}|ago|last active|owner_last_active/i', $json);
        }
    }

    public function test_the_old_sparkle_icon_is_gone_from_every_view_and_script(): void
    {
        foreach (['resources/views', 'resources/js'] as $dir) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir), RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                $this->assertStringNotContainsString('auto_awesome', file_get_contents($file->getPathname()), $file->getPathname());
            }
        }
    }
}
