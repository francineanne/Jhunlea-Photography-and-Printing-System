<?php

namespace Tests\Feature;

use App\Models\PhotographyPackage;
use App\Models\PrintingService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 9C (mobile and accessibility pass): what the server renders for phones, keyboards and screen readers. The
 * visual checks at seven widths are recorded in docs/MOBILE_QA.md.
 */
class MobileAccessibilityTest extends TestCase
{
    use RefreshDatabase;

    private const VIEWPORT = '<meta name="viewport" content="width=device-width, initial-scale=1.0">';

    public function test_every_layout_has_the_viewport_meta_and_a_language(): void
    {
        $owner = User::factory()->owner()->create();
        $customer = User::factory()->create();

        $pages = [
            'public' => $this->get('/'),
            'auth' => $this->get('/login'),
            'error page' => $this->get('/no-such-page'),
            'customer' => $this->actingAs($customer)->get(route('customer.dashboard')),
            'owner' => $this->actingAs($owner)->get(route('owner.dashboard')),
        ];

        foreach ($pages as $layout => $response) {
            $html = $response->getContent();
            $this->assertStringContainsString(self::VIEWPORT, $html, "{$layout}: the viewport meta");
            $this->assertMatchesRegularExpression('/<html lang="[a-z]{2}(-[A-Z]{2})?"/', $html, "{$layout}: the lang attribute");
            $this->assertSame(1, substr_count($html, '<h1'), "{$layout}: exactly one h1");
        }
    }

    public function test_the_chat_tabs_follow_the_aria_tabs_pattern(): void
    {
        $html = $this->actingAs(User::factory()->create())->get(route('customer.assistant.page'))->assertOk()->getContent();

        $this->assertStringContainsString('role="tablist"', $html);
        foreach (['ask', 'studio'] as $tab) {
            $this->assertMatchesRegularExpression('/role="tab" id="chat-tab-'.$tab.'-full"[^>]*aria-controls="chat-panel-full"/', $html);
        }
        $this->assertMatchesRegularExpression('/role="tabpanel" id="chat-panel-full"[^>]*aria-labelledby="chat-tab-ask-full"/', $html);
        $this->assertStringContainsString('x-bind:tabindex="tab === \'ask\' ? 0 : -1"', $html, 'Only the chosen tab is in the Tab order');
        $this->assertStringContainsString('x-on:keydown.arrow-right.prevent=', $html, 'Arrow keys move between the tabs');
    }

    public function test_the_wizards_and_the_chat_say_they_need_javascript(): void
    {
        $customer = User::factory()->create();
        PrintingService::factory()->published()->create();
        PhotographyPackage::factory()->published()->create();

        foreach ([route('customer.printing.create') => 'This order form needs JavaScript.', route('customer.bookings.create') => 'This booking form needs JavaScript.', route('customer.assistant.page') => 'The chat needs JavaScript.'] as $url => $message) {
            $html = $this->actingAs($customer)->get($url)->assertOk()->getContent();
            $this->assertMatchesRegularExpression('/<noscript>\s*<div role="note"[^>]*data-noscript[\s\S]*?'.preg_quote($message, '/').'[\s\S]*?<\/noscript>/', $html, $url);
        }
    }

    public function test_every_field_of_the_sign_in_forms_has_a_label(): void
    {
        foreach (['/login', '/register', '/forgot-password'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            preg_match_all('/<(input|select|textarea)\b(?![^>]*type="(?:hidden|submit|button|checkbox)")[^>]*\bid="([^"]+)"/', $html, $fields);

            $this->assertNotEmpty($fields[2], "{$url} has fields");
            foreach ($fields[2] as $id) {
                $this->assertStringContainsString('for="'.$id.'"', $html, "{$url}: #{$id} has a label");
            }
            $this->assertDoesNotMatchRegularExpression('/<input\b(?![^>]*type="(?:hidden|checkbox)")(?![^>]*\bid=)[^>]*>/', $html, "{$url}: every visible input has an id for its label");
        }
    }

    public function test_a_form_error_is_announced_and_linked_to_its_field(): void
    {
        $this->post('/login', ['email' => '', 'password' => '']);
        $html = $this->followingRedirects()->get('/login')->getContent();

        // The email field points at its error, and the error is announced
        $this->assertMatchesRegularExpression('/name="email"[^>]*aria-invalid="true"\s+aria-describedby="([^"]+-error)"|aria-invalid="true"\s+aria-describedby="([^"]+-error)"[^>]*name="email"/', $html);
        preg_match('/aria-invalid="true"\s+aria-describedby="([^"]+-error)"/', $html, $match);
        $this->assertMatchesRegularExpression('/<p id="'.preg_quote($match[1], '/').'" role="alert"/', $html);
    }

    public function test_the_compare_page_keeps_the_heading_order(): void
    {
        $html = $this->get(route('packages.compare'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<h2 class="mt-4 font-display text-xl font-semibold">Choose at least/', $html);
    }
}
