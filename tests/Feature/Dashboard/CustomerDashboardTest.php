<?php

namespace Tests\Feature\Dashboard;

use App\Enums\BookingStatus as B;
use App\Enums\PrintingOrderStatus as O;
use App\Models\Payment;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Models\User;
use App\Services\DashboardService;
use App\Services\PaymentLedgerService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Phase 5C: the customer dashboard, every number against a hand calculation, own data only. */
class CustomerDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private User $ben;

    protected function setUp(): void
    {
        parent::setUp();

        // Wednesday 4 November 2026, 12:00 in Manila
        $this->travelTo(Carbon::parse('2026-11-04 04:00:00', 'UTC'));
        $this->ana = User::factory()->create(['name' => 'Ana Maria Reyes']);
        $this->ben = User::factory()->create(['name' => 'Ben Cruz']);
    }

    private function order(User $customer, O $status, ?string $price, array $extra = []): PrintingOrder
    {
        return PrintingOrder::factory()->forCustomer($customer)->status($status)->create(['confirmed_price' => $price, 'estimated_price' => '9999.00'] + $extra);
    }

    private function booking(User $customer, B $status, string $start, ?string $price, array $extra = []): PhotographyBooking
    {
        return PhotographyBooking::factory()->forCustomer($customer)->status($status)->at(Carbon::parse($start, 'Asia/Manila')->utc())->create(['confirmed_price' => $price, 'estimated_price' => '8888.00'] + $extra);
    }

    private function unread(User $user, int $count, bool $read = false): void
    {
        foreach (range(1, $count) as $i) {
            $row = new DatabaseNotification;
            $row->forceFill(['id' => (string) Str::uuid(), 'type' => 'order_ready', 'notifiable_type' => 'user', 'notifiable_id' => $user->id,
                'data' => ['kind' => 'order_ready', 'payable_type' => 'printing_order', 'payable_id' => 1, 'reference' => 'PR-1'], 'read_at' => $read ? now() : null])->save();
        }
    }

    /** A busy account for Ana, and some rows for Ben that must never count for her. */
    private function busy(): array
    {
        $o = [
            'submitted' => $this->order($this->ana, O::Submitted, null),
            'info' => $this->order($this->ana, O::UnderReview, null, ['info_request' => 'Please send a PDF.']),
            'accepted' => $this->order($this->ana, O::Accepted, '100.00'),
            'processingPaid' => $this->order($this->ana, O::Processing, '200.50'),
            'ready' => $this->order($this->ana, O::ReadyForPickup, '300.25'),
            'completed' => $this->order($this->ana, O::Completed, '400.00'),
            'cancelled' => $this->order($this->ana, O::Cancelled, '500.00'),
            'rejected' => $this->order($this->ana, O::Rejected, '50.00'),
            'free' => $this->order($this->ana, O::Accepted, '0.00'),
        ];
        Payment::factory()->paying($o['processingPaid'])->create();
        Payment::factory()->paying($o['completed'])->create();

        $b = [
            'future' => $this->booking($this->ana, B::Confirmed, '2026-11-10 10:00', '1000.00'),
            'pastPaid' => $this->booking($this->ana, B::Confirmed, '2026-11-01 10:00', '700.00'),
            'proposed' => $this->booking($this->ana, B::Proposed, '2026-11-12 10:00', null, ['proposed_starts_at' => Carbon::parse('2026-11-12 14:00', 'Asia/Manila')->utc()]),
            'requested' => $this->booking($this->ana, B::Requested, '2026-11-13 10:00', null),
            'cancelled' => $this->booking($this->ana, B::Cancelled, '2026-11-14 10:00', '600.00'),
        ];
        Payment::factory()->paying($b['pastPaid'])->create();

        $this->order($this->ben, O::Accepted, '777.00', ['info_request' => 'Ben only']);
        $this->booking($this->ben, B::Confirmed, '2026-11-11 10:00', '555.00');
        $this->unread($this->ana, 2);
        $this->unread($this->ana, 1, read: true);
        $this->unread($this->ben, 4);

        return [$o, $b];
    }

    public function test_a_brand_new_account_shows_zeros_and_first_steps(): void
    {
        $this->actingAs($this->ana)->get(route('customer.dashboard'))->assertOk()
            ->assertSee('Welcome back, Ana')
            ->assertSee('No printing orders or photoshoots yet')
            ->assertSee('₱0.00')
            ->assertViewHas('data', fn ($data) => $data['activeOrders'] === 0 && $data['upcomingShoots'] === 0 && $data['toPayCents'] === 0 && $data['unread'] === 0 && $data['attention']->isEmpty());
    }

    public function test_every_number_matches_a_hand_calculation(): void
    {
        $this->busy();
        $data = app(DashboardService::class)->customer($this->ana);

        // Submitted, Under review, Accepted (100), Processing, Ready, Accepted (0.00): not Completed, Cancelled or Rejected
        $this->assertSame(6, $data['activeOrders']);
        // The future confirmed shoot and the proposed one; not the past, requested or cancelled ones
        $this->assertSame(2, $data['upcomingShoots']);
        // Confirmed prices with no recorded payment on open orders and confirmed bookings: 100.00 + 300.25 + 1000.00
        $this->assertSame(140025, $data['toPayCents']);
        $this->assertSame(2, $data['unread']);
        $this->assertSame(14, $data['requests']);

        // The same rule as the payments ledger: Ana's share of awaiting() is the same amount
        $ledger = app(PaymentLedgerService::class)->awaiting();
        $anaShare = $ledger['orders']->concat($ledger['bookings'])->where('customer_id', $this->ana->id)->sum(fn ($row) => Money::toCents($row->confirmed_price));
        $this->assertSame($anaShare, $data['toPayCents']);

        $this->actingAs($this->ana)->get(route('customer.dashboard'))->assertSee('₱1,400.25')->assertDontSee('Ben only');
    }

    public function test_needs_attention_lists_real_states_and_they_disappear_when_they_end(): void
    {
        [$o, $b] = $this->busy();

        $titles = fn () => app(DashboardService::class)->customer($this->ana)['attention']->map(fn ($item) => $item['reference'].' '.$item['title'])->all();

        $this->assertEqualsCanonicalizing([
            $o['info']->reference_number.' The shop needs more information',
            $b['proposed']->reference_number.' A new time was proposed',
            $o['ready']->reference_number.' Ready for pickup',
            $o['accepted']->reference_number.' Amount to pay at the shop',
            $b['future']->reference_number.' Amount to pay at the studio',
        ], $titles());

        $o['info']->forceFill(['info_request' => null])->save();
        $b['proposed']->forceFill(['status' => B::Cancelled])->save();
        Payment::factory()->paying($o['accepted'])->create();
        $o['ready']->forceFill(['status' => O::Completed])->save();
        Payment::factory()->paying($o['ready'])->create();
        Payment::factory()->paying($b['future'])->create();

        $this->assertSame([], $titles());
        $this->actingAs($this->ana)->get(route('customer.dashboard'))->assertSee('Nothing needs your attention right now.');
    }

    public function test_the_latest_activity_is_the_five_newest_of_both_kinds(): void
    {
        foreach (range(1, 4) as $i) {
            $this->order($this->ana, O::Submitted, null)->forceFill(['created_at' => now()->subDays(10 - $i)])->save();
            $this->booking($this->ana, B::Requested, '2026-11-20 10:00', null)->forceFill(['created_at' => now()->subDays(10 - $i)->addHour()])->save();
        }

        $latest = app(DashboardService::class)->customer($this->ana)['latest'];

        $this->assertCount(5, $latest);
        $this->assertSame([PhotographyBooking::class, PrintingOrder::class, PhotographyBooking::class, PrintingOrder::class, PhotographyBooking::class], $latest->map(fn ($m) => $m::class)->all());
        $this->actingAs($this->ana)->get(route('customer.dashboard'))->assertSee('See all requests')->assertSee(route('customer.requests.index'), false);
    }

    public function test_the_queries_are_fixed_however_much_data_exists(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->ana)->get(route('customer.dashboard'))->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->busy();
        $few = $count();
        foreach (range(1, 20) as $i) {
            $this->order($this->ana, O::ReadyForPickup, '10.00', ['info_request' => 'More '.$i]);
            $this->booking($this->ana, B::Proposed, '2026-11-25 10:00', null, ['proposed_starts_at' => now()->addDays(5)]);
        }

        $this->assertSame($few, $count());
    }

    public function test_only_customers_and_names_are_escaped(): void
    {
        $this->ana->forceFill(['name' => '<script>alert(1)</script> Reyes'])->save();

        $this->actingAs($this->ana)->get(route('customer.dashboard'))->assertOk()->assertDontSee('<script>alert(1)</script>', false);
        $this->actingAs(User::factory()->owner()->create())->get(route('customer.dashboard'))->assertForbidden();
        auth()->logout();
        $this->get(route('customer.dashboard'))->assertRedirect(route('login'));
    }
}
