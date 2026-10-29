<?php

namespace Tests\Feature\Notifications;

use App\Enums\PrintingOrderStatus as Status;
use App\Models\PrintingOrder;
use App\Models\User;
use App\Notifications\ActivityNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Phase 5B: the bell, the notifications pages, opening and marking, and who may see what. */
class NotificationPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $customer;

    private PrintingOrder $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->owner()->create();
        $this->customer = User::factory()->create();
        $this->order = PrintingOrder::factory()->forCustomer($this->customer)->status(Status::Accepted)->create(['confirmed_price' => '480.00']);
    }

    /** Writes notifications directly, as NotificationDispatcher would, newest last. */
    private function notify(User $user, int $count = 1, string $kind = 'order_accepted', ?PrintingOrder $order = null, bool $read = false): DatabaseNotification
    {
        $order ??= $this->order;
        $row = null;

        foreach (range(1, $count) as $i) {
            $row = new DatabaseNotification;
            $row->forceFill([
                'id' => (string) Str::uuid(),
                'type' => $kind,
                'notifiable_type' => 'user',
                'notifiable_id' => $user->id,
                'data' => ['kind' => $kind, 'payable_type' => 'printing_order', 'payable_id' => $order->id, 'reference' => $order->reference_number, 'amount' => '480.00'],
                'read_at' => $read ? now() : null,
                'created_at' => Carbon::now()->addSeconds($i),
                'updated_at' => Carbon::now()->addSeconds($i),
            ])->save();
        }

        return $row;
    }

    public function test_the_bell_renders_on_a_fresh_install_with_no_badge(): void
    {
        $this->assertSame(0, DatabaseNotification::count());

        $this->actingAs($this->customer)->get(route('customer.dashboard'))->assertOk()
            ->assertSee('aria-label="Notifications"', false)->assertSee('No notifications yet');
        $this->actingAs($this->owner)->get(route('owner.dashboard'))->assertOk()->assertSee('aria-label="Notifications"', false);
    }

    public function test_the_badge_counts_unread_and_caps_at_99(): void
    {
        $this->notify($this->customer, 3);
        $this->notify($this->customer, 2, read: true);
        $this->actingAs($this->customer)->get(route('customer.dashboard'))->assertSee('aria-label="Notifications, 3 unread"', false);

        $this->notify($this->customer, 97);
        $this->actingAs($this->customer)->get(route('customer.dashboard'))->assertSee('aria-label="Notifications, 99+ unread"', false)->assertSee('99+ unread');
    }

    public function test_the_bell_costs_the_same_queries_however_many_notifications(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            // A page without dashboard numbers, so only the bell reads notifications
            $this->actingAs($this->customer)->get(route('customer.printing.index'))->assertOk();
            $queries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'notifications'))->count();
            DB::disableQueryLog();

            return $queries;
        };

        $this->notify($this->customer);
        $few = $count();
        $this->notify($this->customer, 150);

        $this->assertSame($few, $count());
        $this->assertSame(2, $few, 'One query for the unread count and one for the latest items');
    }

    /** MySQL (ONLY_FULL_GROUP_BY) refuses ORDER BY next to COUNT or SUM; SQLite accepts it, so check the SQL itself. */
    public function test_the_count_queries_never_carry_an_order_by(): void
    {
        $this->notify($this->customer, 3);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->customer)->get(route('customer.notifications.index'))->assertOk();
        $this->actingAs($this->customer)->post(route('customer.notifications.read-all'));
        $aggregates = collect(DB::getQueryLog())->pluck('query')
            ->filter(fn ($sql) => str_contains($sql, 'notifications') && preg_match('/count\(|sum\(|^update/i', $sql));
        DB::disableQueryLog();

        $this->assertGreaterThanOrEqual(3, $aggregates->count());
        $aggregates->each(fn ($sql) => $this->assertStringNotContainsStringIgnoringCase('order by', $sql, $sql));
    }

    public function test_the_page_uses_a_fixed_number_of_queries(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->customer)->get(route('customer.notifications.index'))->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->notify($this->customer, 2);
        $few = $count();
        $this->notify($this->customer, 40);

        $this->assertSame($few, $count());
    }

    public function test_the_dropdown_shows_the_latest_eight(): void
    {
        $this->notify($this->customer, 12);
        $html = $this->actingAs($this->customer)->get(route('customer.dashboard'))->getContent();

        $dropdown = Str::between($html, 'aria-label="Latest notifications"', 'See all notifications');
        $this->assertSame(8, substr_count($dropdown, '/open"'));
        $this->assertStringContainsString(route('customer.notifications.index'), $html);
    }

    public function test_the_page_with_all_and_unread_tabs(): void
    {
        $this->notify($this->customer, 25);
        $this->notify($this->customer, 3, read: true);

        $this->actingAs($this->customer)->get(route('customer.notifications.index'))->assertOk()
            ->assertSee('Your order '.$this->order->reference_number.' was accepted at ₱480.00.')
            ->assertViewHas('counts', ['all' => 28, 'unread' => 25])
            ->assertViewHas('items', fn ($items) => $items->count() === 20);

        $this->actingAs($this->customer)->get(route('customer.notifications.index', ['tab' => 'unread', 'page' => 2]))
            ->assertViewHas('items', fn ($items) => $items->count() === 5 && $items->every(fn ($item) => ! $item['read']));

        $this->actingAs($this->customer)->get(route('customer.notifications.index', ['tab' => 'archived']))->assertRedirect(route('customer.notifications.index'));
        $this->actingAs($this->customer)->get(route('customer.notifications.index', ['tab' => ['x']]))->assertRedirect(route('customer.notifications.index'));
        $this->actingAs($this->customer)->get(route('customer.notifications.index', ['tab' => 'unread']))->assertOk();
    }

    public function test_the_empty_states(): void
    {
        $this->actingAs($this->owner)->get(route('owner.notifications.index'))->assertSee('No notifications yet');
        $this->actingAs($this->owner)->get(route('owner.notifications.index', ['tab' => 'unread']))->assertSee('Nothing unread');
    }

    public function test_opening_marks_read_and_lands_on_the_page(): void
    {
        $row = $this->notify($this->customer, kind: 'payment_recorded');

        $this->actingAs($this->customer)->post(route('customer.notifications.open', $row->id))
            ->assertRedirect(route('customer.printing.show', $this->order).'#payment');
        $this->assertNotNull($row->fresh()->read_at);

        $ownerRow = $this->notify($this->owner, kind: 'order_placed');
        $this->actingAs($this->owner)->post(route('owner.notifications.open', $ownerRow->id))->assertRedirect(route('owner.orders.show', $this->order));
    }

    public function test_a_get_never_marks_anything(): void
    {
        $row = $this->notify($this->customer);

        $this->actingAs($this->customer)->get(route('customer.notifications.go', $row->id))->assertRedirect(route('customer.printing.show', $this->order));
        $this->actingAs($this->customer)->get(route('customer.notifications.index'));
        $this->actingAs($this->customer)->get(route('customer.dashboard'));
        $this->assertContains($this->actingAs($this->customer)->get('/my/notifications/'.$row->id.'/open')->status(), [404, 405]);
        $this->actingAs($this->customer)->get('/my/notifications/read-all')->assertNotFound();

        $this->assertNull($row->fresh()->read_at);
    }

    public function test_mark_one_and_mark_all_are_idempotent(): void
    {
        $row = $this->notify($this->customer);
        $this->notify($this->customer, 4);

        $this->actingAs($this->customer)->from(route('customer.notifications.index'))->post(route('customer.notifications.read', $row->id))->assertRedirect(route('customer.notifications.index'));
        $first = $row->fresh()->read_at;
        $this->travel(5)->minutes();
        $this->actingAs($this->customer)->post(route('customer.notifications.read', $row->id))->assertRedirect();
        $this->assertEquals($first, $row->fresh()->read_at);

        $this->actingAs($this->customer)->post(route('customer.notifications.read-all'))->assertSessionHas('status', 'All notifications are marked as read.');
        $this->actingAs($this->customer)->post(route('customer.notifications.read-all'))->assertSessionHas('status', 'Everything was already read.');
        $this->assertSame(0, $this->customer->unreadNotifications()->count());
    }

    public function test_a_target_that_is_gone_or_forbidden_goes_back_to_the_list(): void
    {
        $gone = PrintingOrder::factory()->forCustomer($this->customer)->create();
        $row = $this->notify($this->customer, order: $gone);
        $gone->delete();

        $this->actingAs($this->customer)->post(route('customer.notifications.open', $row->id))
            ->assertRedirect(route('customer.notifications.index'))->assertSessionHas('error', 'That order or booking is no longer available to you, so there is nothing to open.');

        $theirs = PrintingOrder::factory()->create();
        $forbidden = $this->notify($this->customer, order: $theirs);
        $this->actingAs($this->customer)->get(route('customer.notifications.go', $forbidden->id))->assertRedirect(route('customer.notifications.index'));

        $booking = new DatabaseNotification;
        $booking->forceFill(['id' => (string) Str::uuid(), 'type' => 'booking_confirmed', 'notifiable_type' => 'user', 'notifiable_id' => $this->customer->id,
            'data' => ['kind' => 'booking_confirmed', 'payable_type' => 'photography_booking', 'payable_id' => 999999, 'reference' => 'PB-000009']])->save();
        $this->actingAs($this->customer)->post(route('customer.notifications.open', $booking->id))->assertRedirect(route('customer.notifications.index'));
    }

    public function test_only_your_own_notifications_and_only_in_your_area(): void
    {
        $mine = $this->notify($this->customer);
        $other = User::factory()->create();
        $theirs = $this->notify($other);
        $ownerRow = $this->notify($this->owner, kind: 'order_placed');

        foreach (['open', 'read'] as $action) {
            $this->actingAs($this->customer)->post(route("customer.notifications.{$action}", $theirs->id))->assertNotFound();
            $this->actingAs($this->customer)->post(route("customer.notifications.{$action}", $ownerRow->id))->assertNotFound();
        }
        $this->actingAs($this->customer)->get(route('customer.notifications.go', $theirs->id))->assertNotFound();
        $this->actingAs($this->customer)->post(route('customer.notifications.open', (string) Str::uuid()))->assertNotFound();
        $this->assertContains($this->actingAs($this->customer)->post('/my/notifications/not-a-uuid/open')->status(), [404, 405]);
        $this->assertNull($theirs->fresh()->read_at);

        // The areas are separate
        $this->actingAs($this->owner)->get(route('customer.notifications.index'))->assertForbidden();
        $this->actingAs($this->customer)->get(route('owner.notifications.index'))->assertForbidden();
        $this->actingAs($this->owner)->post(route('customer.notifications.read', $mine->id))->assertForbidden();
        $this->actingAs($this->customer)->get(route('customer.notifications.index'))->assertDontSee($theirs->id);

        auth()->logout();
        $this->get(route('customer.notifications.index'))->assertRedirect(route('login'));
        $this->post(route('owner.notifications.read-all'))->assertRedirect(route('login'));
        $this->post(route('customer.notifications.open', $mine->id))->assertRedirect(route('login'));
        $this->assertNull($mine->fresh()->read_at);
    }

    public function test_hostile_text_is_escaped_in_the_bell_and_the_page(): void
    {
        $order = PrintingOrder::factory()->forCustomer($this->customer)->create(['reference_number' => '<b>PR</b>']);
        $this->notify($this->customer, order: $order);
        $this->notify($this->owner, kind: 'order_placed', order: $order);
        $this->owner->forceFill(['name' => '<script>alert(1)</script>'])->save();

        foreach ([[$this->customer, 'customer.dashboard'], [$this->customer, 'customer.notifications.index'], [$this->owner, 'owner.notifications.index']] as [$user, $page]) {
            $this->actingAs($user)->get(route($page))->assertOk()->assertDontSee('<b>PR</b>', false)->assertSee('&lt;b&gt;PR&lt;/b&gt;', false)
                ->assertDontSee('<script>alert(1)</script>', false);
        }
    }

    public function test_the_owner_sidebar_shows_the_unread_count(): void
    {
        $this->notify($this->owner, 4, kind: 'order_placed');

        $this->actingAs($this->owner)->get(route('owner.orders.index'))->assertSee('<span class="sr-only">, </span>4<span class="sr-only"> unread</span>', false);
    }

    public function test_the_notification_class_writes_only_the_database(): void
    {
        $notification = new ActivityNotification(['kind' => 'order_placed', 'payable_type' => 'printing_order', 'payable_id' => 1, 'reference' => 'PR-1']);

        $this->assertSame(['database'], $notification->via($this->owner));
        $this->assertSame('order_placed', $notification->databaseType($this->owner));
    }
}
