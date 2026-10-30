<?php

namespace Tests\Feature\Chat;

use App\Models\Conversation;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** The customer chat screens (Phase 7C): where the launcher appears, the full page, and what the page trusts. */
class ChatPagesTest extends TestCase
{
    use ChatHelpers, RefreshDatabase;

    private const LAUNCHER = 'data-chat-launcher';

    /**
     * The settings the page hands to the Alpine component, read back out of @js().
     *
     * @return array<string, mixed>
     */
    private function chatConfig(TestResponse $response): array
    {
        $this->assertSame(1, preg_match("/x-data=\"chatWindow\\(JSON\\.parse\\('(.+?)'\\)\\)\"/", $response->getContent(), $match));

        // @js() is a JSON string inside a JSON string
        return json_decode(json_decode('"'.$match[1].'"', flags: JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_the_page_renders_for_a_customer_and_is_refused_for_guests_and_the_owner(): void
    {
        $customer = $this->customer();

        $this->actingAs($customer)->get(route('customer.assistant.page'))->assertOk()
            ->assertSee(config('assistant.name'))
            ->assertSee('data-chat-window="full"', false)
            ->assertDontSee(self::LAUNCHER, false);

        $this->actingAs($this->owner())->get(route('customer.assistant.page'))->assertForbidden();

        auth()->logout();
        $this->get(route('customer.assistant.page'))->assertRedirect(route('login'));
    }

    public function test_opening_the_page_creates_no_conversation_by_itself(): void
    {
        $customer = $this->customer();

        $this->actingAs($customer)->get(route('customer.assistant.page'))->assertOk();
        $this->actingAs($customer)->get(route('customer.dashboard'))->assertOk();

        $this->assertSame(0, Conversation::query()->count());
    }

    public function test_the_launcher_is_on_public_and_customer_pages_and_nowhere_else(): void
    {
        $this->get(route('home'))->assertOk()->assertSee(self::LAUNCHER, false)->assertSee('data-chat-window="compact"', false);
        $this->get(route('packages.index'))->assertOk()->assertSee(self::LAUNCHER, false);

        foreach (['login', 'register', 'password.request'] as $route) {
            $this->get(route($route))->assertOk()->assertDontSee(self::LAUNCHER, false);
        }

        $customer = $this->customer();
        $this->actingAs($customer)->get(route('customer.dashboard'))->assertOk()->assertSee(self::LAUNCHER, false);
        $this->actingAs($customer)->get(route('home'))->assertOk()->assertSee(self::LAUNCHER, false);

        $owner = $this->owner();
        $this->actingAs($owner)->get(route('owner.dashboard'))->assertOk()->assertDontSee(self::LAUNCHER, false);
        $this->actingAs($owner)->get(route('home'))->assertOk()->assertDontSee(self::LAUNCHER, false);
    }

    public function test_the_error_pages_have_no_launcher(): void
    {
        $this->get('/this-page-does-not-exist')->assertNotFound()->assertDontSee(self::LAUNCHER, false);

        foreach (['403', '404', '405', '413', '419', '429'] as $page) {
            $this->assertStringNotContainsString(self::LAUNCHER, view("errors.{$page}")->render(), $page);
        }
    }

    public function test_the_chat_never_renders_html_from_data(): void
    {
        foreach (['resources/views/components/chat/window.blade.php', 'resources/js/chat.js'] as $file) {
            $source = file_get_contents(base_path($file));

            $this->assertStringNotContainsString('x-html', $source, $file);
            $this->assertStringNotContainsString('innerHTML', $source, $file);
        }

        $this->assertStringNotContainsString('{!!', file_get_contents(base_path('resources/views/components/chat/window.blade.php')));
    }

    public function test_a_guest_gets_the_guest_endpoint_and_the_sign_in_note_and_no_customer_urls(): void
    {
        $response = $this->get(route('home'))->assertOk()
            ->assertSee('Sign in to message the studio')
            ->assertSee('Signing in saves this conversation.')
            ->assertSee(route('login'), false);

        $config = $this->chatConfig($response);
        $this->assertTrue($config['guest']);
        $this->assertSame(['guest' => route('assistant.guest')], $config['urls']);
    }

    public function test_a_customer_gets_the_stored_conversation_urls_and_not_the_guest_endpoint(): void
    {
        $config = $this->chatConfig($this->actingAs($this->customer())->get(route('customer.dashboard'))->assertOk());

        $this->assertFalse($config['guest']);
        $this->assertArrayHasKey('assistantIndex', $config['urls']);
        $this->assertArrayNotHasKey('guest', $config['urls']);
    }

    public function test_the_about_parameter_shows_only_the_customers_own_record(): void
    {
        $customer = $this->customer();
        $order = PrintingOrder::factory()->forCustomer($customer)->create(['reference_number' => 'PR-000777']);
        $booking = PhotographyBooking::factory()->forCustomer($customer)->create(['reference_number' => 'PB-000888']);
        $foreign = PrintingOrder::factory()->forCustomer($this->customer())->create(['reference_number' => 'PR-000999']);

        $own = $this->chatConfig($this->actingAs($customer)->get(route('customer.assistant.page', ['tab' => 'studio', 'about' => 'printing_order:'.$order->id]))->assertOk());
        $this->assertSame(['type' => 'printing_order', 'id' => $order->id, 'reference' => 'PR-000777'], $own['about']);

        $shoot = $this->chatConfig($this->actingAs($customer)->get(route('customer.assistant.page', ['tab' => 'studio', 'about' => 'photography_booking:'.$booking->id]))->assertOk());
        $this->assertSame('PB-000888', $shoot['about']['reference']);

        foreach (['printing_order:'.$foreign->id, 'printing_order:999999', 'user:1', 'printing_order', 'printing_order:1 OR 1=1'] as $about) {
            $page = $this->actingAs($customer)->get(route('customer.assistant.page', ['tab' => 'studio', 'about' => $about]))->assertOk()->assertDontSee('PR-000999');
            $this->assertNull($this->chatConfig($page)['about'], $about);
        }

        // Even if the page were edited by hand, the server refuses it when the message is sent
        $this->actingAs($customer)->postJson(route('customer.messages.store', $this->studio($customer)), ['body' => 'x', 'about' => ['type' => 'printing_order', 'id' => $foreign->id]])
            ->assertStatus(422)->assertJson(['code' => 'invalid_about']);
    }

    public function test_the_tab_parameter_falls_back_to_ask_lumi(): void
    {
        $customer = $this->customer();

        $this->assertSame('studio', $this->chatConfig($this->actingAs($customer)->get(route('customer.assistant.page', ['tab' => 'studio'])))['tab']);
        $this->assertSame('ask', $this->chatConfig($this->actingAs($customer)->get(route('customer.assistant.page', ['tab' => 'anything'])))['tab']);
    }

    public function test_the_order_and_booking_pages_link_to_the_studio_chat(): void
    {
        $customer = $this->customer();
        $order = PrintingOrder::factory()->forCustomer($customer)->create();
        $booking = PhotographyBooking::factory()->forCustomer($customer)->create();

        $this->actingAs($customer)->get(route('customer.printing.show', $order))->assertOk()
            ->assertSee(route('customer.assistant.page', ['tab' => 'studio', 'about' => 'printing_order:'.$order->id]));
        $this->actingAs($customer)->get(route('customer.bookings.show', $booking))->assertOk()
            ->assertSee(route('customer.assistant.page', ['tab' => 'studio', 'about' => 'photography_booking:'.$booking->id]));
    }

    public function test_the_policy_text_is_truthful_and_mentions_no_online_payment_method(): void
    {
        $page = $this->actingAs($this->customer())->get(route('customer.assistant.page'))->assertOk();

        $page->assertSee('is an AI assistant', false)->assertSee('The studio confirms everything.');

        foreach (['credit card', 'Maya', 'debit', 'calibration', 'attach_file', '>mic<'] as $invented) {
            $page->assertDontSee($invented, false);
        }
    }
}
