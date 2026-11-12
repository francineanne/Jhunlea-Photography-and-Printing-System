<?php

namespace Tests\MySql;

use App\Enums\AuditAction;
use App\Enums\BookingStatus;
use App\Enums\PrintingOrderStatus;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Models\User;
use App\Services\Audit\AuditChain;
use App\Services\Audit\AuditLogger;
use App\Services\PaymentLedgerService;
use App\Services\Reports\IncomePeriod;
use App\Services\Reports\IncomeReportService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\MySqlTestCase;

/**
 * Phase 9B: what SQLite hides. Laravel's strict MySQL mode (ONLY_FULL_GROUP_BY, STRICT_TRANS_TABLES) on every heavy
 * screen; LIKE with % and _ typed as text; case-insensitive collation of emails and reference numbers; money in
 * cents; JSON meta and utf8mb4 text in the audit chain; the Manila day boundary.
 */
class SqlBehaviourTest extends MySqlTestCase
{
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->owner()->create(['name' => 'SQL Owner']);
        Cache::flush();
    }

    private function paidOrder(User $customer, string $price, string $receivedAtUtc, array $orderAttributes = []): Payment
    {
        $order = PrintingOrder::factory()->status(PrintingOrderStatus::Processing)->forCustomer($customer)->create(['confirmed_price' => $price] + $orderAttributes);

        return Payment::factory()->paying($order)->create(['received_by' => $this->owner->id, 'received_at' => Carbon::parse($receivedAtUtc, 'UTC')]);
    }

    public function test_every_heavy_screen_runs_under_strict_group_by(): void
    {
        $this->travelTo(Carbon::parse('2026-11-13 04:00:00', 'UTC'));
        $ana = User::factory()->create(['name' => 'Ana Strict']);
        $walkIn = User::factory()->walkIn()->create(['name' => 'Walk Strict']);
        $this->paidOrder($ana, '480.00', '2026-11-12 02:00:00');
        $this->paidOrder($walkIn, '120.50', '2026-11-13 01:00:00', ['source' => 'walk_in']);
        PrintingOrder::factory()->status(PrintingOrderStatus::Accepted)->forCustomer($ana)->create(['confirmed_price' => '75.00']);
        $booking = PhotographyBooking::factory()->status(BookingStatus::Confirmed)->forCustomer($ana)->create(['confirmed_price' => '1500.00']);
        Payment::factory()->paying($booking)->create(['received_by' => $this->owner->id, 'received_at' => Carbon::parse('2026-11-10 03:00:00', 'UTC')]);
        app(AuditLogger::class)->record(AuditAction::LoggedIn, actor: $this->owner);

        $this->actingAs($this->owner);
        $owner = [
            route('owner.dashboard'),
            route('owner.payments.index', ['range' => 'all']), route('owner.payments.index'),
            route('owner.reports.index', ['range' => 'all']), route('owner.reports.index', ['range' => 'this_month']),
            route('owner.customers.index'), route('owner.customers.index', ['q' => 'Ana', 'type' => 'online']), route('owner.customers.index', ['awaiting' => 1]),
            route('owner.customers.show', $ana), route('owner.orders.index'), route('owner.orders.index', ['source' => 'walk_in']), route('owner.bookings.index'),
            route('owner.activity.index', ['range' => 'all']), route('owner.reports.end-of-day', ['date' => '2026-11-12']),
        ];
        foreach (['daily', 'weekly', 'monthly', 'all'] as $view) {
            $owner[] = route('owner.reports.income', ['view' => $view, 'date' => '2026-11-12']);
        }
        $owner[] = route('owner.reports.income', ['view' => 'custom', 'from' => '2026-11-01', 'to' => '2026-11-13']);
        $owner[] = route('owner.reports.income', ['view' => 'custom', 'from' => '2026-01-01', 'to' => '2026-11-13']);

        foreach ($owner as $url) {
            $this->get($url)->assertOk();
        }
        $this->get(route('owner.reports.csv', ['view' => 'all']))->assertOk()->streamedContent();

        // The income report equals the ledger on MySQL too, and the parts add up
        $report = app(IncomeReportService::class)->build(IncomePeriod::resolve('all', null, null, null, Carbon::now('Asia/Manila')));
        $this->assertSame(48000 + 12050 + 150000, $report['cents']);
        $this->assertSame($report['cents'], array_sum(array_column($report['sources'], 'cents')), 'Online + Walk-in = income');
        $this->assertSame($report['cents'], array_sum(array_column($report['kinds'], 'cents')), 'Printing + Photography = income');
        $this->assertSame(12050, $report['sources']['walk_in']['cents']);

        auth()->logout();
        $this->actingAs($ana)->get(route('customer.requests.index'))->assertOk()->assertSee($booking->reference_number);
        $this->get(route('customer.requests.index', ['kind' => 'printing', 'q' => 'PR']))->assertOk();
        $this->get(route('customer.dashboard'))->assertOk();
    }

    public function test_percent_and_underscore_are_searched_as_text(): void
    {
        $percent = User::factory()->create(['name' => 'Ana 100% Real']);
        $underscore = User::factory()->create(['name' => 'Cara_Underscore']);
        $plain = User::factory()->create(['name' => 'Ben Plain']);
        $paid = [$this->paidOrder($percent, '100.00', 'now'), $this->paidOrder($underscore, '100.00', 'now'), $this->paidOrder($plain, '100.00', 'now')];
        [$percentRef, $underscoreRef, $plainRef] = array_map(fn (Payment $p) => $p->payable->reference_number, $paid);

        $this->actingAs($this->owner);
        $this->get(route('owner.customers.index', ['q' => '%']))->assertOk()->assertSee('Ana 100% Real')->assertDontSee('Ben Plain')->assertDontSee('Cara_Underscore');
        $this->get(route('owner.customers.index', ['q' => '_']))->assertOk()->assertSee('Cara_Underscore')->assertDontSee('Ben Plain')->assertDontSee('Ana 100% Real');

        $this->get(route('owner.payments.index', ['q' => '%', 'range' => 'all']))->assertOk()->assertSee($percentRef)->assertDontSee($plainRef)->assertDontSee($underscoreRef);
        $this->get(route('owner.payments.index', ['q' => '_', 'range' => 'all']))->assertOk()->assertSee($underscoreRef)->assertDontSee($plainRef)->assertDontSee($percentRef);

        $logger = app(AuditLogger::class);
        $logger->record(AuditAction::LoggedIn, summary: 'Signed in from the 100% branch.', actor: $this->owner);
        $logger->record(AuditAction::LoggedIn, summary: 'Signed in from the main_branch.', actor: $this->owner);
        $logger->record(AuditAction::LoggedIn, summary: 'Signed in from the plain office.', actor: $this->owner);
        $this->get(route('owner.activity.index', ['q' => '%', 'range' => 'all']))->assertOk()->assertSee('100% branch')->assertDontSee('main_branch')->assertDontSee('plain office');
        $this->get(route('owner.activity.index', ['q' => '_', 'range' => 'all']))->assertOk()->assertSee('main_branch')->assertDontSee('100% branch')->assertDontSee('plain office');
    }

    public function test_emails_and_reference_numbers_are_unique_ignoring_case(): void
    {
        User::factory()->create(['email' => 'Ana@Example.test']);

        // Registration says it is taken (the app's own check), and the database itself refuses it as well
        $this->post('/register', ['name' => 'Ana Again', 'email' => 'ana@example.test', 'phone' => '0917 123 4567', 'password' => 'Password1', 'password_confirmation' => 'Password1'])
            ->assertSessionHasErrors('email');
        try {
            User::factory()->create(['email' => 'ANA@example.TEST']);
            $this->fail('MySQL accepted the same email in another case');
        } catch (UniqueConstraintViolationException) {
            $this->addToAssertionCount(1);
        }

        $order = PrintingOrder::factory()->create(['reference_number' => 'PR-000001']);
        try {
            PrintingOrder::factory()->create(['reference_number' => 'pr-000001']);
            $this->fail('MySQL accepted the same reference number in another case');
        } catch (UniqueConstraintViolationException) {
            $this->addToAssertionCount(1);
        }

        // The counter finds it typed in lower case and without the hyphen
        $this->actingAs($this->owner)->getJson(route('owner.payments.counter.search', ['q' => 'pr000001']))->assertOk()->assertSee($order->reference_number);
    }

    public function test_money_round_trips_in_cents(): void
    {
        $customer = User::factory()->create();
        foreach (['1234.56', '0.10', '0.20', '99999999.99'] as $amount) {
            $this->paidOrder($customer, $amount, 'now');
        }

        $this->assertSame(['1234.56', '0.10', '0.20', '99999999.99'], Payment::query()->orderBy('id')->pluck('amount')->all());
        $today = Carbon::now('Asia/Manila')->toDateString();
        $totals = app(PaymentLedgerService::class)->totals(['method' => null, 'status' => null, 'search' => '', 'from' => $today, 'to' => $today]);
        $this->assertSame(123456 + 10 + 20 + 9999999999, $totals['cents'], 'SUM(ROUND(amount * 100)) is exact, no float drift');
        $this->assertSame(4, $totals['count']);
    }

    public function test_the_hash_chain_survives_a_mysql_round_trip_with_json_emoji_and_filipino_text(): void
    {
        $order = PrintingOrder::factory()->create(['reference_number' => 'PR-424242']);
        $logger = app(AuditLogger::class);
        $logger->record(AuditAction::OrderPriceChanged, $order, ['old_cents' => 50000, 'new_cents' => 65000, 'reason' => 'Dagdag na pahina, salamat po 😊 ñ'], actor: $this->owner,
            subjectLabel: 'Pagbati sa Señora 🎉 PR-424242');
        $logger->record(AuditAction::LoggedIn, actor: $this->owner);

        $row = AuditLog::query()->where('action', AuditAction::OrderPriceChanged->value)->sole();
        $this->assertSame('Pagbati sa Señora 🎉 PR-424242', $row->subject_label);
        $this->assertSame('Dagdag na pahina, salamat po 😊 ñ', $row->meta['reason']);
        $this->assertSame(65000, $row->meta['new_cents']);

        $this->artisan('audit:verify')->assertSuccessful();
        $this->assertTrue(app(AuditChain::class)->quick()['ok']);
    }

    public function test_a_payment_at_the_manila_midnight_lands_on_the_right_day(): void
    {
        $this->travelTo(Carbon::parse('2026-11-13 12:00:00', 'Asia/Manila'));
        $customer = User::factory()->create();
        $before = $this->paidOrder($customer, '111.00', '2026-11-12 15:59:59'); // 23:59:59 Manila, Nov 12
        $after = $this->paidOrder($customer, '222.00', '2026-11-12 16:00:00');  // 00:00:00 Manila, Nov 13

        $report = app(IncomeReportService::class);
        $today = Carbon::now('Asia/Manila');
        $nov12 = $report->build(IncomePeriod::resolve('daily', '2026-11-12', null, null, $today));
        $nov13 = $report->build(IncomePeriod::resolve('daily', '2026-11-13', null, null, $today));
        $this->assertSame([11100, 1], [$nov12['cents'], $nov12['count']]);
        $this->assertSame([22200, 1], [$nov13['cents'], $nov13['count']]);

        // The hour bars (grouped in SQL with the +8 hours shift) put them at 23:00 on the 12th and 00:00 on the 13th
        $busy = fn (array $report) => collect($report['series'])->filter(fn ($bar) => $bar['cents'] > 0)->pluck('cents', 'key')->all();
        $this->assertSame(['23' => 11100], $busy($nov12));
        $this->assertSame(['00' => 22200], $busy($nov13));

        $this->assertSame([$before->id], $report->dayPayments('2026-11-12')['recorded']->pluck('id')->all());
        $this->assertSame([$after->id], $report->dayPayments('2026-11-13')['recorded']->pluck('id')->all());

        $this->actingAs($this->owner)->get(route('owner.reports.end-of-day', ['date' => '2026-11-12']))->assertOk()
            ->assertSee($before->reference_number)->assertDontSee($after->reference_number);
    }
}
