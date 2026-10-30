<?php

namespace Tests\Feature\Chat;

use App\Enums\MessageSender;
use App\Models\ChatMessage;
use App\Models\PhotographyPackage;
use App\Models\PrintingOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Paging a thread, the inbox, and query counts that do not grow with the data (Phase 7B). */
class ThreadPagesTest extends TestCase
{
    use ChatHelpers, RefreshDatabase;

    public function test_the_first_page_is_the_latest_fifty_oldest_first_then_earlier_and_newer(): void
    {
        $customer = $this->customer();
        $studio = $this->studio($customer);
        $this->rows($studio, 120, MessageSender::Customer, $customer->id);
        $ids = $studio->messages()->orderBy('id')->pluck('id')->all();

        $first = $this->actingAs($customer)->getJson(route('customer.messages.show', $studio))->assertOk();
        $this->assertSame(array_slice($ids, 70), array_column($first->json('messages'), 'id'));
        $first->assertJsonPath('state.has_more_before', true);

        $earlier = $this->actingAs($customer)->getJson(route('customer.messages.show', [$studio, 'before' => $ids[70]]))->assertOk();
        $this->assertSame(array_slice($ids, 20, 50), array_column($earlier->json('messages'), 'id'));
        $earlier->assertJsonPath('state.has_more_before', true);

        $oldest = $this->actingAs($customer)->getJson(route('customer.messages.show', [$studio, 'before' => $ids[20]]))->assertOk();
        $this->assertSame(array_slice($ids, 0, 20), array_column($oldest->json('messages'), 'id'));
        $oldest->assertJsonPath('state.has_more_before', false);

        $newer = $this->actingAs($customer)->getJson(route('customer.messages.show', [$studio, 'after' => $ids[117]]))->assertOk();
        $this->assertSame(array_slice($ids, 118), array_column($newer->json('messages'), 'id'));

        $none = $this->actingAs($customer)->getJson(route('customer.messages.show', [$studio, 'after' => $ids[119]]))->assertOk();
        $this->assertSame([], $none->json('messages'));
        $none->assertJsonPath('state.poll', ['open' => 5, 'idle' => 30, 'long_idle' => 60]);

        $this->actingAs($customer)->getJson(route('customer.messages.show', [$studio, 'after' => 1, 'before' => 5]))->assertStatus(422);
    }

    public function test_a_thread_costs_the_same_queries_with_few_or_many_messages(): void
    {
        $small = $this->threadQueries(3);
        $large = $this->threadQueries(60);

        $this->assertSame($small, $large);
    }

    public function test_the_inbox_costs_the_same_queries_with_few_or_many_threads(): void
    {
        $owner = $this->owner();
        $this->threads(2, $owner);
        $this->actingAs($owner)->getJson(route('owner.messages.index'))->assertOk();
        $small = $this->countQueries(fn () => $this->actingAs($owner)->getJson(route('owner.messages.index'))->assertOk());

        $this->threads(15, $owner);
        $large = $this->countQueries(fn () => $this->actingAs($owner)->getJson(route('owner.messages.index'))->assertOk()->assertJsonCount(17, 'conversations'));

        $this->assertSame($small, $large);
    }

    public function test_the_inbox_lists_studio_threads_with_their_state_and_filters(): void
    {
        $owner = $this->owner();
        $ana = $this->customer(['name' => 'Ana Reyes']);
        $ben = $this->customer(['name' => 'Ben Cruz']);
        $cora = $this->customer(['name' => 'Cora Lim']);
        $this->askLumi($ana);
        $this->studio($cora); // no messages yet: not listed

        $anaThread = $this->studio($ana);
        $this->say($anaThread, $ana, 'First');
        $this->say($anaThread, $ana, 'Second');

        $benThread = $this->studio($ben);
        $this->say($benThread, $ben, 'Hi');
        $this->ownerSays($benThread, $owner, 'Hello Ben');
        $benThread->forceFill(['needs_owner' => true])->save();
        $order = PrintingOrder::factory()->forCustomer($ben)->create(['reference_number' => 'PR-000321']);

        $all = $this->actingAs($owner)->getJson(route('owner.messages.index'))->assertOk()->assertJsonCount(2, 'conversations');
        $row = collect($all->json('conversations'))->firstWhere('id', $anaThread->id);
        $this->assertSame(['name' => 'Ana Reyes'], $row['customer']);
        $this->assertSame('Second', $row['preview']);
        $this->assertSame('customer', $row['last_sender_type']);
        $this->assertSame(2, $row['unread_count']);
        $this->assertTrue($row['needs_reply']);
        $this->assertFalse($row['needs_owner']);
        $this->assertArrayHasKey('iso', $row['last_message_at']);

        $ids = fn (TestResponse $response) => array_column($response->json('conversations'), 'id');
        $this->assertSame([$anaThread->id], $ids($this->actingAs($owner)->getJson(route('owner.messages.index', ['filter' => 'needs_reply']))));
        $this->assertSame([$benThread->id], $ids($this->actingAs($owner)->getJson(route('owner.messages.index', ['filter' => 'needs_owner']))));
        $this->assertSame([$anaThread->id], $ids($this->actingAs($owner)->getJson(route('owner.messages.index', ['filter' => 'unread']))));
        $this->assertSame([$benThread->id], $ids($this->actingAs($owner)->getJson(route('owner.messages.index', ['q' => 'cruz']))));
        $this->assertSame([$benThread->id], $ids($this->actingAs($owner)->getJson(route('owner.messages.index', ['q' => $order->reference_number]))));
        $this->assertSame([], $ids($this->actingAs($owner)->getJson(route('owner.messages.index', ['q' => '%']))));
        $this->actingAs($owner)->getJson(route('owner.messages.index', ['filter' => 'everything']))->assertStatus(422);
    }

    private function threadQueries(int $count): int
    {
        $customer = $this->customer();
        $studio = $this->studio($customer);
        $owner = $this->owner();
        $package = PhotographyPackage::factory()->published()->create();
        $order = PrintingOrder::factory()->forCustomer($customer)->create();

        for ($i = 0; $i < $count; $i++) {
            ChatMessage::factory()->from(MessageSender::Customer, $customer->id)->create(['conversation_id' => $studio->id, 'meta' => ['about' => ['type' => 'printing_order', 'id' => $order->id]]]);
            ChatMessage::factory()->from(MessageSender::Owner, $owner->id)->create(['conversation_id' => $studio->id]);
            ChatMessage::factory()->from(MessageSender::Assistant)->create(['conversation_id' => $studio->id, 'meta' => ['intent' => 'recommend', 'recommend' => [['type' => 'package', 'id' => $package->id]]]]);
        }

        // One request first: the test keeps one application (and its cached controllers) across requests, so the
        // first request also loads the settings row; the counted request is then the same every time
        $owners = fn () => $this->actingAs($owner)->getJson(route('owner.messages.show', $studio))->assertOk();
        $customers = fn () => $this->actingAs($customer)->getJson(route('customer.messages.show', $studio))->assertOk();
        $owners();
        $customers();

        return $this->countQueries($owners) + $this->countQueries($customers);
    }

    private function threads(int $count, User $owner): void
    {
        for ($i = 0; $i < $count; $i++) {
            $customer = $this->customer();
            $thread = $this->studio($customer);
            $this->rows($thread, 3, MessageSender::Customer, $customer->id);
            $thread->forceFill(['last_message_at' => now(), 'last_sender_type' => MessageSender::Customer])->save();
        }
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
