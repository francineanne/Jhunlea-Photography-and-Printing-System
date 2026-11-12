<?php

namespace Tests\MySql;

use App\Enums\AuditAction;
use App\Enums\BookingStatus;
use App\Enums\PrintingOrderStatus;
use App\Exceptions\BookingTransitionException;
use App\Exceptions\InvalidStatusTransitionException;
use App\Exceptions\PaymentException;
use App\Models\AuditLog;
use App\Models\AvailabilityRule;
use App\Models\BookingSetting;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\Payment;
use App\Models\PhotographyBooking;
use App\Models\PhotographyPackage;
use App\Models\PrintingOrder;
use App\Models\PrintingService;
use App\Models\User;
use App\Services\Audit\AuditChain;
use App\Services\Audit\AuditSubject;
use App\Services\BookingService;
use App\Services\Chat\ConversationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\Race;
use Tests\MySqlTestCase;

/**
 * Phase 9B: the money, booking, audit and chat paths under REAL concurrency on MySQL. Each scenario starts 2 to 8 PHP
 * processes that act at the same moment through the real services (Race, RaceWorkerCommand), and repeats five times
 * to catch rare interleavings. "error" outcomes are exceptions that would reach the owner or customer (a 500): every
 * test requires none, and names the MySQL error code (1213 deadlock, 1205 lock wait timeout) when one appears.
 */
class RaceConditionsTest extends MySqlTestCase
{
    private const REPEATS = 5;

    private User $owner;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->owner()->create(['name' => 'Race Owner']);
        $this->customer = User::factory()->create(['name' => 'Race Customer']);
    }

    private function order(string $price = '500.00', PrintingOrderStatus $status = PrintingOrderStatus::Accepted): PrintingOrder
    {
        return PrintingOrder::factory()->status($status)->forCustomer($this->customer)->create(['confirmed_price' => $price]);
    }

    /** @param list<array<string, mixed>> $results */
    private function assertNoErrors(array $results, string $scenario): void
    {
        $errors = array_values(array_filter($results, fn ($r) => $r['outcome'] === 'error'));
        $this->assertSame([], $errors, "{$scenario}: an exception reached the caller: ".json_encode(array_slice($errors, 0, 3)));
    }

    /** @param list<array{late: bool}> $outputs */
    private function assertStartedTogether(array $outputs, string $scenario): void
    {
        $this->assertSame([], array_keys(array_filter(array_column($outputs, 'late'))), "{$scenario}: a worker booted after the start time; raise Race::LEAD_SECONDS");
    }

    private function assertChainIntact(): void
    {
        Cache::forget(AuditChain::LAST_FULL);
        $result = app(AuditChain::class)->verify();
        $this->assertTrue($result['ok'], 'Audit chain: '.($result['message'] ?? ''));

        $head = DB::table('audit_chain_head')->where('id', 1)->first();
        $newest = DB::table('audit_logs')->orderByDesc('id')->first();
        $this->assertSame([(int) $newest->id, $newest->hash], [(int) $head->last_id, $head->last_hash], 'The chain head must equal the newest row');
        $this->assertSame(DB::table('audit_logs')->count(), DB::table('audit_logs')->distinct()->count('prev_hash'), 'Two rows share a prev_hash: the chain forked');
    }

    public function test_a_audit_chain_with_eight_writers_at_once(): void
    {
        foreach (range(1, self::REPEATS) as $repeat) {
            $outputs = Race::run('audit', array_map(fn ($i) => ['count' => 10, 'inner' => $i % 2 === 1], range(0, 7)));
            $this->assertStartedTogether($outputs, 'audit');
            $results = Race::results($outputs);
            $this->assertNoErrors($results, 'audit');

            $this->assertSame(80 * $repeat, AuditLog::query()->count());
            $this->assertChainIntact();
        }
    }

    public function test_b_four_payments_for_the_same_order_at_once(): void
    {
        foreach (range(1, self::REPEATS) as $repeat) {
            $order = $this->order();
            $outputs = Race::run('pay', array_fill(0, 4, ['order_id' => $order->id, 'owner_id' => $this->owner->id, 'method' => 'cash']));
            $this->assertStartedTogether($outputs, 'pay');
            $results = Race::results($outputs);
            $this->assertNoErrors($results, 'pay');

            $ok = array_values(array_filter($results, fn ($r) => $r['outcome'] === 'ok'));
            $this->assertCount(1, $ok, 'Exactly one payment is recorded');
            foreach (array_filter($results, fn ($r) => $r['outcome'] === 'refused') as $refused) {
                $this->assertSame(PaymentException::alreadyPaid()->getMessage(), $refused['reason']);
            }

            $payments = Payment::query()->where('payable_type', 'printing_order')->where('payable_id', $order->id)->get();
            $this->assertCount(1, $payments);
            $this->assertSame(1, AuditLog::query()->where('action', AuditAction::PaymentRecorded->value)->where('subject_type', AuditSubject::type($payments->first()))->where('subject_id', $payments->first()->id)->count());
        }
        $this->assertChainIntact();
    }

    public function test_c_the_same_gcash_reference_on_two_orders_at_once(): void
    {
        foreach (range(1, self::REPEATS) as $repeat) {
            $reference = 'RACE-'.Str::upper(Str::random(8));
            $orders = [$this->order(), $this->order()];
            $outputs = Race::run('pay', array_map(fn ($order) => ['order_id' => $order->id, 'owner_id' => $this->owner->id, 'method' => 'gcash', 'gcash' => $reference], $orders));
            $this->assertStartedTogether($outputs, 'gcash');
            $results = Race::results($outputs);
            $this->assertNoErrors($results, 'gcash');

            $this->assertCount(1, array_filter($results, fn ($r) => $r['outcome'] === 'ok'));
            $refused = array_values(array_filter($results, fn ($r) => $r['outcome'] === 'refused'));
            $this->assertCount(1, $refused);
            $this->assertSame(PaymentException::gcashReferenceTaken()->getMessage(), $refused[0]['reason']);
            $this->assertSame(1, Payment::query()->where('gcash_reference', $reference)->count());
        }
    }

    public function test_d_a_payment_and_a_price_change_on_the_same_order_at_once(): void
    {
        foreach (range(1, self::REPEATS) as $repeat) {
            $order = $this->order('500.00');

            // Both workers in one start: one pays at the price the owner saw, the other changes the price
            $both = $this->pair($order);
            $this->assertStartedTogether($both, 'pay vs price');
            $results = Race::results($both);
            $this->assertNoErrors($results, 'pay vs price');

            [$pay, $price] = [$results[0], $results[1]];
            $order->refresh();
            $payment = Payment::query()->where('payable_type', 'printing_order')->where('payable_id', $order->id)->where('status', 'recorded')->first();

            if ($payment !== null) {
                // Paid first: the price stayed and the change was refused because a payment is recorded
                $this->assertSame('500.00', $payment->amount);
                $this->assertSame($order->confirmed_price, $payment->amount, 'Never a payment against a stale price');
                $this->assertSame('refused', $price['outcome']);
                $this->assertSame(InvalidStatusTransitionException::priceLocked()->getMessage(), $price['reason']);
            } else {
                // Price first: the payment at the old price was refused
                $this->assertSame('650.00', $order->confirmed_price);
                $this->assertSame('refused', $pay['outcome']);
                $this->assertSame(PaymentException::priceChanged('₱650.00')->getMessage(), $pay['reason']);
            }
        }
    }

    /** One payment worker and one price worker, started together. @return list<array<string, mixed>> */
    private function pair(PrintingOrder $order): array
    {
        return Race::runMixed([
            ['pay', ['order_id' => $order->id, 'owner_id' => $this->owner->id, 'method' => 'cash', 'expected' => '500.00']],
            ['price', ['order_id' => $order->id, 'owner_id' => $this->owner->id, 'price' => '650.00']],
        ]);
    }

    private function openSchedule(): PhotographyPackage
    {
        foreach (range(0, 6) as $weekday) {
            $rule = new AvailabilityRule(['is_open' => true, 'opens_at' => '06:00:00', 'closes_at' => '22:00:00']);
            $rule->weekday = $weekday;
            $rule->save();
        }
        $settings = new BookingSetting;
        $settings->fill(['buffer_minutes' => 0, 'min_notice_hours' => 0, 'max_advance_days' => 60, 'concurrent_sessions' => 1]);
        $settings->id = BookingSetting::ROW_ID;
        $settings->save();

        return PhotographyPackage::factory()->published()->create(['duration_minutes' => 60, 'max_people' => 3, 'price' => '1500.00', 'price_requires_confirmation' => false]);
    }

    /** A Manila 10:00 a few days ahead, as UTC, plus an hour offset. */
    private function slot(int $days, int $hourOffset = 0): Carbon
    {
        return Carbon::now('Asia/Manila')->addDays($days)->setTime(10 + $hourOffset, 0)->utc();
    }

    public function test_e_two_bookings_confirmed_for_the_same_slot_at_once(): void
    {
        $package = $this->openSchedule();

        foreach (range(1, self::REPEATS) as $repeat) {
            $start = $this->slot(3 + $repeat);
            $bookings = collect(range(1, 2))->map(fn () => PhotographyBooking::factory()->status(BookingStatus::Requested)->forCustomer(User::factory()->create())
                ->at($start)->create(['photography_package_id' => $package->id, 'estimated_price' => '1500.00']));

            $outputs = Race::run('confirm', $bookings->map(fn ($b) => ['booking_id' => $b->id, 'owner_id' => $this->owner->id])->all());
            $this->assertStartedTogether($outputs, 'confirm');
            $results = Race::results($outputs);
            $this->assertNoErrors($results, 'confirm');

            $this->assertCount(1, array_filter($results, fn ($r) => $r['outcome'] === 'ok'), 'Exactly one is confirmed');
            $refused = array_values(array_filter($results, fn ($r) => $r['outcome'] === 'refused'));
            $this->assertCount(1, $refused);
            $this->assertSame(BookingTransitionException::conflict()->getMessage(), $refused[0]['reason']);
            $this->assertSame(1, PhotographyBooking::query()->whereIn('id', $bookings->pluck('id'))->where('status', BookingStatus::Confirmed->value)->count());
        }
    }

    public function test_f_one_customer_sends_more_requests_at_once_than_the_limit(): void
    {
        $package = $this->openSchedule();
        $limit = (int) config('booking.max_open_requests_per_customer');

        foreach (range(1, self::REPEATS) as $repeat) {
            $customer = User::factory()->create();
            $payloads = array_map(fn ($i) => ['customer_id' => $customer->id, 'package_id' => $package->id, 'starts_at' => $this->slot(10 + $repeat, $i)->toIso8601String()], range(0, $limit + 2));

            $outputs = Race::run('book', $payloads);
            $this->assertStartedTogether($outputs, 'book');
            $results = Race::results($outputs);
            $this->assertNoErrors($results, 'book');

            $this->assertCount($limit, array_filter($results, fn ($r) => $r['outcome'] === 'ok'));
            foreach (array_filter($results, fn ($r) => $r['outcome'] === 'refused') as $refused) {
                $this->assertSame(BookingService::requestLimitMessage(), $refused['reason']);
            }
            $this->assertSame($limit, PhotographyBooking::query()->where('customer_id', $customer->id)->whereIn('status', [BookingStatus::Requested->value, BookingStatus::Proposed->value])->count());
        }
    }

    public function test_g_one_studio_thread_and_one_message_per_client_id(): void
    {
        foreach (range(1, self::REPEATS) as $repeat) {
            $customer = User::factory()->create();

            $outputs = Race::run('studio', array_fill(0, 4, ['customer_id' => $customer->id]));
            $this->assertStartedTogether($outputs, 'studio');
            $results = Race::results($outputs);
            $this->assertNoErrors($results, 'studio');
            $this->assertCount(1, array_unique(array_column($results, 'value')), 'Every worker got the same thread');
            $this->assertSame(1, Conversation::query()->studio()->forCustomer($customer)->count());

            $thread = app(ConversationService::class)->studioFor($customer);
            $clientId = (string) Str::uuid();
            $outputs = Race::run('send', array_fill(0, 4, ['conversation_id' => $thread->id, 'customer_id' => $customer->id, 'client_id' => $clientId, 'body' => "Hello, race {$repeat}"]));
            $results = Race::results($outputs);
            $this->assertNoErrors($results, 'send');

            $this->assertSame(1, ChatMessage::query()->where('client_id', $clientId)->count(), 'One message for one client_id');
            $this->assertCount(1, array_unique(array_map(fn ($r) => $r['value']['id'], $results)));
            $this->assertCount(1, array_filter($results, fn ($r) => $r['value']['stored_now']), 'Only one request stored it');
        }
    }

    public function test_h_mixed_load_for_twenty_seconds(): void
    {
        $orders = collect(range(1, 5))->map(fn () => $this->order(number_format(mt_rand(200, 800), 2, '.', '')));
        $walkIn = User::factory()->walkIn()->create(['name' => 'Race Walk-in']);
        $service = PrintingService::factory()->published()->create(['name' => 'Race Printing', 'base_price' => '5.00']);

        $outputs = Race::run('mixed', array_map(fn ($i) => [
            'seed' => 1000 + $i, 'seconds' => 20, 'owner_id' => $this->owner->id, 'order_ids' => $orders->pluck('id')->all(),
            'walk_in_customer_id' => $walkIn->id, 'service_id' => $service->id,
        ], range(0, 5)), Race::LEAD_SECONDS, 240);
        $this->assertStartedTogether($outputs, 'mixed');
        $results = Race::results($outputs);

        $errors = array_values(array_filter($results, fn ($r) => $r['outcome'] === 'error'));
        $codes = array_count_values(array_map(fn ($r) => (string) ($r['code'] ?? $r['exception']), $errors));
        fwrite(STDERR, PHP_EOL.'[mixed] actions: '.count($results).', ok: '.count(array_filter($results, fn ($r) => $r['outcome'] === 'ok'))
            .', refused: '.count(array_filter($results, fn ($r) => $r['outcome'] === 'refused')).', errors: '.json_encode($codes).PHP_EOL);
        $this->assertSame([], $errors, 'Exceptions reached the caller (1213 = deadlock, 1205 = lock wait timeout): '.json_encode(array_slice($errors, 0, 3)));

        $this->assertChainIntact();

        // Every payment row has exactly one "recorded" audit row; every voided one exactly one "voided" row
        foreach (Payment::query()->get() as $payment) {
            $rows = AuditLog::query()->where('subject_type', AuditSubject::type($payment))->where('subject_id', $payment->id);
            $this->assertSame(1, (clone $rows)->where('action', AuditAction::PaymentRecorded->value)->count(), "payment {$payment->id}");
            $this->assertSame($payment->status->value === 'voided' ? 1 : 0, (clone $rows)->where('action', AuditAction::PaymentVoided->value)->count(), "payment {$payment->id} void row");
        }

        // No order or booking ever holds two recorded payments
        $this->assertSame(0, DB::table('payments')->select('payable_type', 'payable_id')->where('status', 'recorded')->groupBy('payable_type', 'payable_id')->havingRaw('count(*) > 1')->get()->count());

        // Every recorded payment is for its order's confirmed price at the end (the price is locked while paid)
        foreach (Payment::query()->where('status', 'recorded')->where('payable_type', 'printing_order')->get() as $payment) {
            $this->assertSame(PrintingOrder::query()->find($payment->payable_id)->confirmed_price, $payment->amount);
        }
    }
}
