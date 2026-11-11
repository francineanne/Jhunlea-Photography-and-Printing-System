<?php

namespace Tests\Feature\Assistant\Owner;

use App\Models\OwnerAssistantMessage;
use App\Models\User;
use App\Services\Assistant\Owner\ToolResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\Support\FakesAssistant;
use Tests\Support\SeedsOwnerShop;
use Tests\TestCase;

/**
 * The owner's Ask Lumi UI (Phase 7E-3b): the full page, the panel on every owner page (owners only), the "Today at a
 * glance" card, history paging, CSRF, escaping of hostile text and that every link is a relative owner path.
 */
class OwnerAskPageTest extends TestCase
{
    use FakesAssistant, RefreshDatabase, SeedsOwnerShop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAssistant();
        $this->seedOwnerShop();
    }

    private function turn(string $role, string $text, array $meta = []): OwnerAssistantMessage
    {
        $row = new OwnerAssistantMessage;
        $row->owner_id = $this->owner->id;
        $row->role = $role;
        $row->text = $text;
        $row->meta = $meta ?: null;
        $row->save();

        return $row;
    }

    public function test_the_owner_opens_the_full_page_with_the_nav_item_and_no_floating_panel(): void
    {
        $this->turn('owner', 'How much today?');
        $this->turn('lumi', 'Today you recorded ₱1,625.00.');

        $this->actingAs($this->owner)->get(route('owner.assistant.index'))->assertOk()
            ->assertSee('data-owner-ask="full"', false)
            ->assertDontSee('data-owner-ask="compact"', false)
            ->assertSee('data-owner-ask-static', false)
            ->assertSee('Today you recorded ₱1,625.00.')
            ->assertSee('Turn on JavaScript to ask')
            ->assertSee('href="'.route('owner.assistant.index').'"', false)
            ->assertSee('name="csrf-token"', false);
    }

    public function test_the_panel_is_on_every_owner_page_for_the_owner_only(): void
    {
        foreach (['owner.dashboard', 'owner.orders.index', 'owner.payments.index', 'owner.messages.index'] as $page) {
            $this->actingAs($this->owner)->get(route($page))->assertOk()->assertSee('data-owner-ask="compact"', false)->assertSee('data-owner-ask-launcher', false);
        }

        $customer = User::factory()->create();
        $this->actingAs($customer)->get(route('customer.dashboard'))->assertOk()->assertDontSee('data-owner-ask', false);
        $this->get(route('home'))->assertOk()->assertDontSee('data-owner-ask', false);
    }

    public function test_customers_and_guests_cannot_reach_the_page_or_the_endpoints(): void
    {
        $customer = User::factory()->create();

        $this->get(route('owner.assistant.index'))->assertRedirect(route('login'));
        $this->getJson(route('owner.assistant.history'))->assertUnauthorized();
        $this->actingAs($customer)->get(route('owner.assistant.index'))->assertForbidden();
        $this->actingAs($customer)->getJson(route('owner.assistant.briefing'))->assertForbidden();
        $this->actingAs($customer)->postJson(route('owner.assistant.ask'), ['message' => 'Income?'])->assertForbidden();
        $this->assertSame([], $this->fake->requests);
    }

    public function test_the_overview_has_today_at_a_glance_and_its_endpoint_needs_no_provider(): void
    {
        Http::fake();

        $this->actingAs($this->owner)->get(route('owner.dashboard'))->assertOk()
            ->assertSee('data-owner-glance', false)
            ->assertSee('Today at a glance')
            ->assertSee('owner\/assistant\/briefing', false);

        $items = $this->actingAs($this->owner)->getJson(route('owner.assistant.briefing'))->assertOk()->json('items');
        // 8D: the seeded shop has an unpaid order ready for pickup and an unpaid walk-in session today, so their counter
        // lines are shown too (only when above zero)
        $this->assertCount(8, $items);
        $this->assertContains('Ready for pickup, not paid yet: 1', array_column($items, 'text'));
        $this->assertContains('Walk-in sessions today, not paid yet: 1', array_column($items, 'text'));
        $this->assertStringStartsWith('Recorded payments today: ₱', $items[0]['text']);
        $this->assertSame('/owner/payments', $items[0]['url']);
        $this->assertSame('/owner/messages?filter=unanswered', $items[7]['url']);
        $this->assertSame([], $this->fake->requests);
        Http::assertNothingSent();
    }

    public function test_history_is_paged_with_a_cursor(): void
    {
        for ($i = 1; $i <= 60; $i++) {
            $this->turn($i % 2 ? 'owner' : 'lumi', "Turn {$i}");
        }

        $first = $this->actingAs($this->owner)->getJson(route('owner.assistant.history'))->assertOk()->json();
        $this->assertCount(50, $first['messages']);
        $this->assertSame('Turn 11', $first['messages'][0]['text']);
        $this->assertSame('Turn 60', $first['messages'][49]['text']);
        $this->assertNotNull($first['older']);

        $second = $this->actingAs($this->owner)->getJson(route('owner.assistant.history', ['before' => $first['older']]))->assertOk()->json();
        $this->assertSame(['Turn 1', 'Turn 10'], [$second['messages'][0]['text'], $second['messages'][9]['text']]);
        $this->assertCount(10, $second['messages']);
        $this->assertNull($second['older']);

        // A cursor that is not a number reads the latest page
        $this->assertCount(50, $this->actingAs($this->owner)->getJson(route('owner.assistant.history', ['before' => 'x']))->json('messages'));
    }

    public function test_every_write_route_is_in_the_web_group_with_csrf(): void
    {
        $route = Route::getRoutes()->getByName('owner.assistant.ask');

        $this->assertSame(['POST'], $route->methods());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('role:owner', $route->gatherMiddleware());
    }

    public function test_hostile_text_is_escaped_and_links_are_relative_owner_paths(): void
    {
        $hostile = '<img src=x onerror=alert(1)> Maria';
        $this->turn('owner', '<script>alert(1)</script>');
        $this->turn('lumi', $hostile, ['cards' => [['label' => $hostile, 'url' => '/owner/customers/1']], 'links' => []]);

        $page = $this->actingAs($this->owner)->get(route('owner.assistant.index'))->assertOk();
        $page->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<img src=x', false)->assertSee('&lt;img src=x', false);

        // The component draws answers and cards with x-text only
        $view = (string) file_get_contents(resource_path('views/components/owner/ask-lumi.blade.php'));
        $this->assertStringNotContainsString('x-html', $view);
        $this->assertStringContainsString('x-text="card.label"', $view);
        $this->assertStringContainsString('x-text="row.text"', $view);

        $this->assertSame($hostile, $this->actingAs($this->owner)->getJson(route('owner.assistant.history'))->json('messages.1.cards.0.label'), 'JSON carries it as data');

        foreach (['http://localhost:8000/owner/payments?from=2026-11-01' => '/owner/payments?from=2026-11-01', '/owner/printing-orders' => '/owner/printing-orders',
            'https://evil.test/login' => null, 'javascript:alert(1)' => null, '/my/dashboard' => null, '/owner/../my' => null, '//evil.test/owner' => '/owner'] as $url => $expected) {
            $this->assertSame($expected, ToolResult::ownerPath($url), $url);
        }
    }
}
