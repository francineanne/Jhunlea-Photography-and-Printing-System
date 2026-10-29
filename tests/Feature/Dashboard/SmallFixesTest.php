<?php

namespace Tests\Feature\Dashboard;

use App\Models\PrintingOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Phase 5C: the small fixes from the 5B review, checked in the markup and the repository files. */
class SmallFixesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_bell_link_to_all_notifications_carries_the_group_class(): void
    {
        $html = $this->actingAs(User::factory()->create())->get(route('customer.dashboard'))->getContent();

        $this->assertMatchesRegularExpression('/<a href="[^"]*\/my\/notifications" class="group [^"]*">\s*See all notifications/', $html);
    }

    public function test_the_open_without_marking_link_is_in_the_flow_not_over_the_card_button(): void
    {
        $customer = User::factory()->create();
        $order = PrintingOrder::factory()->forCustomer($customer)->create();
        $row = new DatabaseNotification;
        $row->forceFill(['id' => (string) Str::uuid(), 'type' => 'order_ready', 'notifiable_type' => 'user', 'notifiable_id' => $customer->id,
            'data' => ['kind' => 'order_ready', 'payable_type' => 'printing_order', 'payable_id' => $order->id, 'reference' => $order->reference_number]])->save();

        $html = $this->actingAs($customer)->get(route('customer.notifications.index'))->getContent();

        $this->assertMatchesRegularExpression('/<a href="[^"]*\/my\/notifications\/'.$row->id.'" class="([^"]*)"/', $html);
        preg_match('/<a href="[^"]*\/my\/notifications\/'.$row->id.'" class="([^"]*)"/', $html, $match);
        $this->assertStringNotContainsString('absolute', $match[1]);
        $this->assertStringContainsString('min-h-11', $match[1]);
    }

    public function test_gitignore_no_longer_lists_the_tracked_agent_files(): void
    {
        $lines = array_map('trim', file(base_path('.gitignore')));

        $this->assertNotContains('AGENTS.md', $lines);
        $this->assertNotContains('CLAUDE.md', $lines);
        $this->assertNotContains('# Local-only AI agent files', $lines);
        $this->assertContains('/.claude/', $lines);
    }
}
