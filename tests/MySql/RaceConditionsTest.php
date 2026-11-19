<?php

namespace Tests\MySql;

use App\Enums\AuditAction;
use App\Enums\BookingStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
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
use App\Services\PaymentService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\Race;
use Tests\MySqlTestCase;
use Tests\Support\SetsPaymentRules;

/**
 * Phase 9B: the money, booking, audit and chat paths under REAL concurrency on MySQL. Each scenario starts 2 to 8 PHP
 * processes that act at the same moment through the real services (Race, RaceWorkerCommand), and repeats five times
 * to catch rare interleavings. "error" outcomes are exceptions that would reach the owner or customer (a 500): every
 * test requires none, and names the MySQL error code (1213 deadlock, 1205 lock wait timeout) when one appears.
 */
class RaceConditionsTest extends MySqlTestCase
{
    use SetsPaymentRules;

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
        $settings->fill(['buffer_minutes' => 0, 'min_notice_days' => 1, 'max_advance_days' => 60, 'concurrent_sessions' => 1]);
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
        // Written before Phase 10B: no downpayment (the downpayment races are test_k to test_n)
        $this->withoutDownpaymentsOrPrepayment();

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

    // Phase 10A: a request HOLDS its time. Four customers ask for the same time at the same moment: exactly one
    // booking holds it, the others read the friendly refusal, and no deadlock or exception reaches anyone
    public function test_i_four_customers_request_the_same_time_at_once(): void
    {
        $package = $this->openSchedule();

        foreach (range(1, self::REPEATS) as $repeat) {
            $start = $this->slot(20 + $repeat);
            $customers = User::factory()->count(4)->create();

            $outputs = Race::run('book', $customers->map(fn (User $customer) => ['customer_id' => $customer->id, 'package_id' => $package->id, 'starts_at' => $start->toIso8601String()])->all());
            $this->assertStartedTogether($outputs, 'same time');
            $results = Race::results($outputs);
            $this->assertNoErrors($results, 'same time');

            $this->assertCount(1, array_filter($results, fn ($r) => $r['outcome'] === 'ok'), 'Exactly one request is saved');
            $refused = array_values(array_filter($results, fn ($r) => $r['outcome'] === 'refused'));
            $this->assertCount(3, $refused);
            foreach ($refused as $result) {
                $this->assertSame(BookingService::SLOT_JUST_TAKEN_MESSAGE, $result['reason']);
            }

            $at = PhotographyBooking::query()->where('starts_at', $start)->get();
            $this->assertCount(1, $at, 'One booking exists for that time');
            $this->assertTrue($at[0]->holdsTime());
            $this->assertSame(1, PhotographyBooking::query()->holdingTime()->where('starts_at', $start)->count());
        }
    }

    // Phase 10A: a customer's request and the owner's walk-in booking (with a NEW walk-in customer, so an audit row is
    // written inside the schedule lock) for the same time at the same moment: exactly one wins
    public function test_j_a_customer_request_and_a_walk_in_for_the_same_time_at_once(): void
    {
        $package = $this->openSchedule();
        // Written before Phase 10B: no downpayment (the downpayment races are test_k to test_n)
        $this->withoutDownpaymentsOrPrepayment();

        foreach (range(1, self::REPEATS) as $repeat) {
            $start = $this->slot(30 + $repeat);
            $payload = ['package_id' => $package->id, 'starts_at' => $start->toIso8601String()];

            $outputs = Race::runMixed([
                ['book', $payload + ['customer_id' => User::factory()->create()->id]],
                ['walk_in_book', $payload + ['owner_id' => $this->owner->id]],
            ]);
            $this->assertStartedTogether($outputs, 'request and walk-in');
            $results = Race::results($outputs);
            $this->assertNoErrors($results, 'request and walk-in');

            $this->assertCount(1, array_filter($results, fn ($r) => $r['outcome'] === 'ok'), 'Exactly one wins');
            $refused = array_values(array_filter($results, fn ($r) => $r['outcome'] === 'refused'));
            $this->assertCount(1, $refused);
            $this->assertSame($refused[0]['op'] === 'book' ? BookingService::SLOT_JUST_TAKEN_MESSAGE : BookingTransitionException::conflict()->getMessage(), $refused[0]['reason']);
            $this->assertSame(1, PhotographyBooking::query()->where('starts_at', $start)->count());
            $this->assertSame(1, PhotographyBooking::query()->holdingTime()->where('starts_at', $start)->count());
        }

        // The loser's new walk-in customer was rolled back with its booking, and the audit chain is whole
        $this->assertSame(PhotographyBooking::query()->where('source', 'walk_in')->count(), User::query()->where('is_walk_in', true)->count());
        $this->assertChainIntact();
    }

    /** Phase 10B: a confirmed photoshoot waiting for its downpayment, ₱1,000.00 (downpayment ₱500.00 at the default 50%). */
    private function awaitingDownpayment(int $daysAhead, ?Carbon $due = null): PhotographyBooking
    {
        return PhotographyBooking::factory()->status(BookingStatus::AwaitingDownpayment)->forCustomer(User::factory()->create())
            ->at($this->slot($daysAhead))->create(['confirmed_price' => '1000.00', 'downpayment_due_at' => $due ?? $this->slot($daysAhead)->subDays(3)]);
    }

    /** No order or booking ever has more recorded than its price, nor two active payments of one kind. */
    private function assertPaymentsConsistent(): void
    {
        $over = DB::table('payments')->where('status', 'recorded')->select('payable_type', 'payable_id', DB::raw('SUM(amount) as paid'))->groupBy('payable_type', 'payable_id')->get()
            ->filter(fn ($row) => (float) $row->paid > (float) DB::table($row->payable_type === 'printing_order' ? 'printing_orders' : 'photography_bookings')->where('id', $row->payable_id)->value('confirmed_price') + 0.001);
        $this->assertSame([], $over->all(), 'A record has more recorded than its price');
        $this->assertSame(0, DB::table('payments')->whereNotNull('active')->select('payable_type', 'payable_id', 'kind')->groupBy('payable_type', 'payable_id', 'kind')->havingRaw('COUNT(*) > 1')->count());
        // A balance never stands without its downpayment
        $this->assertSame(0, DB::table('payments as b')->where('b.kind', 'balance')->whereNotNull('b.active')
            ->whereNotExists(fn ($q) => $q->from('payments as d')->whereColumn('d.payable_id', 'b.payable_id')->whereColumn('d.payable_type', 'b.payable_type')->where('d.kind', 'downpayment')->whereNotNull('d.active'))->count());
    }

    // Phase 10B (a): four owners record the downpayment of the same booking at once: one payment, the booking confirmed
    public function test_k_four_owners_record_the_same_downpayment_at_once(): void
    {
        $this->openSchedule();

        foreach (range(1, self::REPEATS) as $repeat) {
            $booking = $this->awaitingDownpayment(40 + $repeat);
            $owners = User::factory()->owner()->count(4)->create();

            $outputs = Race::run('pay_booking', $owners->map(fn (User $owner) => ['booking_id' => $booking->id, 'owner_id' => $owner->id, 'kind' => 'downpayment'])->all());
            $this->assertStartedTogether($outputs, 'downpayment');
            $results = Race::results($outputs);
            $this->assertNoErrors($results, 'downpayment');

            $this->assertCount(1, array_filter($results, fn ($r) => $r['outcome'] === 'ok'), 'Exactly one downpayment');
            foreach (array_filter($results, fn ($r) => $r['outcome'] === 'refused') as $refused) {
                $this->assertSame(PaymentException::recordBalanceInstead('₱500.00')->getMessage(), $refused['reason']);
            }
            $this->assertSame(1, Payment::query()->where('payable_id', $booking->id)->where('payable_type', 'photography_booking')->count());
            $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
        }

        $this->assertPaymentsConsistent();
        $this->assertChainIntact();
    }

    // Phase 10B (b): the downpayment and the expiry at the same moment: paid and confirmed, or expired and cancelled with
    // no payment; never a cancelled booking that holds a payment
    public function test_l_a_downpayment_and_the_expiry_at_once(): void
    {
        $this->openSchedule();
        $outcomes = [];

        foreach (range(1, self::REPEATS) as $repeat) {
            $booking = $this->awaitingDownpayment(50 + $repeat, Carbon::now('UTC')->subMinute());

            $outputs = Race::runMixed([
                ['pay_booking', ['booking_id' => $booking->id, 'owner_id' => $this->owner->id, 'kind' => 'downpayment']],
                ['expire', ['booking_id' => $booking->id]],
            ]);
            $this->assertStartedTogether($outputs, 'pay vs expire');
            $results = Race::results($outputs);
            $this->assertNoErrors($results, 'pay vs expire');

            $booking->refresh();
            $paid = Payment::recordedFor($booking)->exists();
            $outcomes[] = $booking->status->value;
            $this->assertTrue(
                ($booking->status === BookingStatus::Confirmed && $paid) || ($booking->status === BookingStatus::Cancelled && ! $paid),
                "Inconsistent: {$booking->status->value}, paid: ".($paid ? 'yes' : 'no'),
            );
        }

        fwrite(STDERR, '[pay vs expire] '.json_encode(array_count_values($outcomes)).PHP_EOL);
        $this->assertChainIntact();
    }

    // Phase 10B (c): the balance and a void of the downpayment at once: either the balance stands with its downpayment
    // (the void is refused), or the downpayment is voided and the booking waits for one again (the balance is refused)
    public function test_m_a_balance_and_a_void_of_the_downpayment_at_once(): void
    {
        $this->openSchedule();

        foreach (range(1, self::REPEATS) as $repeat) {
            $booking = $this->awaitingDownpayment(60 + $repeat);
            app(PaymentService::class)->record($booking, $this->owner, PaymentMethod::Cash, null, null, PaymentKind::Downpayment);

            $outputs = Race::runMixed([
                ['pay_booking', ['booking_id' => $booking->id, 'owner_id' => $this->owner->id, 'kind' => 'balance']],
                ['void_booking', ['booking_id' => $booking->id, 'owner_id' => $this->owner->id, 'kind' => 'downpayment']],
            ]);
            $this->assertStartedTogether($outputs, 'balance vs void');
            $results = Race::results($outputs);
            $this->assertNoErrors($results, 'balance vs void');
            $this->assertCount(1, array_filter($results, fn ($r) => $r['outcome'] === 'ok'), 'Exactly one of the two');

            $booking->refresh();
            $kinds = Payment::recordedFor($booking)->pluck('kind')->map->value->sort()->values()->all();
            $this->assertTrue(
                ($kinds === ['balance', 'downpayment'] && $booking->status === BookingStatus::Confirmed)
                || ($kinds === [] && $booking->status === BookingStatus::AwaitingDownpayment),
                'Inconsistent: '.json_encode($kinds).' '.$booking->status->value,
            );
        }

        $this->assertPaymentsConsistent();
        $this->assertChainIntact();
    }

    // Phase 10B (d): six workers record and void downpayments, full payments and balances on ten bookings for fifteen
    // seconds: the chain is whole, nothing is over its price, one active payment of each kind
    public function test_n_mixed_booking_payments_for_fifteen_seconds(): void
    {
        $this->openSchedule();
        $bookings = collect(range(1, 10))->map(fn ($i) => $this->awaitingDownpayment(70 + $i));

        $outputs = Race::run('booking_mixed', array_map(fn ($i) => [
            'seed' => 2000 + $i, 'seconds' => 15, 'owner_id' => $this->owner->id, 'booking_ids' => $bookings->pluck('id')->all(),
        ], range(0, 5)), Race::LEAD_SECONDS, 240);
        $results = Race::results($outputs);

        $errors = array_values(array_filter($results, fn ($r) => $r['outcome'] === 'error'));
        $counts = ['actions' => count($results), 'ok' => count(array_filter($results, fn ($r) => $r['outcome'] === 'ok')), 'refused' => count(array_filter($results, fn ($r) => $r['outcome'] === 'refused'))];
        fwrite(STDERR, '[booking mixed] '.json_encode($counts + ['errors' => array_slice($errors, 0, 3)]).PHP_EOL);
        $this->assertSame([], $errors);
        $this->assertGreaterThan(50, $counts['ok']);

        $this->assertPaymentsConsistent();
        $this->assertChainIntact();
        foreach ($bookings as $booking) {
            $booking->refresh();
            $kinds = Payment::recordedFor($booking)->pluck('kind')->map->value->all();
            // Any recorded payment means confirmed; without one it waits for the downpayment (or stays confirmed after a
            // voided full payment, the one way back the rules do not send it to "Awaiting downpayment")
            $this->assertContains($booking->status, $kinds === [] ? [BookingStatus::AwaitingDownpayment, BookingStatus::Confirmed] : [BookingStatus::Confirmed], $booking->reference_number.' '.json_encode($kinds));
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
