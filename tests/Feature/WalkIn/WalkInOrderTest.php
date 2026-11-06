<?php

namespace Tests\Feature\WalkIn;

use App\Enums\OptionGroup;
use App\Enums\PaymentStatus;
use App\Enums\PricingType;
use App\Enums\PrintingOrderStatus;
use App\Enums\RecordSource;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\PrintingOrder;
use App\Models\PrintingService;
use App\Models\PrintingServiceOption;
use App\Models\User;
use App\Services\PrintingOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** Phase 8A: a walk-in printing order is saved together with its full payment, or not at all. */
class WalkInOrderTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    private User $owner;

    private PrintingService $service;

    private PrintingServiceOption $a4;

    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->owner = User::factory()->owner()->create();
        $this->service = PrintingService::factory()->published()->create([
            'name' => 'Document Printing', 'pricing_type' => PricingType::PerPage, 'base_price' => '5.00', 'allowed_file_types' => ['pdf'], 'max_file_size_mb' => 2,
        ]);
        $this->a4 = PrintingServiceOption::factory()->create(['printing_service_id' => $this->service->id, 'group' => OptionGroup::PaperSize, 'label' => 'A4', 'price_modifier' => '1.00']);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function pdf(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, self::PDF);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, 'walk-in.pdf', null, null, true);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'counter_token' => (string) Str::uuid(),
            'new_customer_name' => 'Lola Walk-in',
            'new_customer_phone' => '0917 555 0101',
            'service' => $this->service->id,
            'options' => [$this->service->id => ['paper_size' => $this->a4->id]],
            'quantity' => 2,
            'pages' => 3,
            'price_choice' => 'catalog',
            'method' => 'cash',
            'cash_tendered' => '50',
            'collected' => '1',
        ], $overrides);
    }

    private function send(array $payload, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)->post(route('owner.orders.walk-in.store'), $payload);
    }

    private function assertNothingSaved(): void
    {
        $this->assertSame(0, PrintingOrder::query()->count());
        $this->assertSame(0, OrderStatusHistory::query()->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, User::query()->where('is_walk_in', true)->count());
    }

    public function test_the_order_is_saved_accepted_and_paid_in_one_go(): void
    {
        $response = $this->send($this->payload());

        $order = PrintingOrder::query()->sole();
        $response->assertRedirect(route('owner.orders.walk-in.done', $order));

        // (5.00 + 1.00) × 3 pages × 2 copies = 36.00
        $this->assertSame(RecordSource::WalkIn, $order->source);
        // Paid at the counter, so printing starts at once (decided with the shop)
        $this->assertSame(PrintingOrderStatus::Processing, $order->status);
        $this->assertSame('36.00', $order->confirmed_price);
        $this->assertMatchesRegularExpression('/^PR-\d{6}$/', $order->reference_number);
        $this->assertTrue($order->customer->isWalkIn());
        $this->assertSame('Lola Walk-in', $order->customer->name);
        $this->assertNull($order->customer->email);

        $payment = Payment::query()->sole();
        $this->assertSame('36.00', $payment->amount);
        $this->assertSame($this->owner->id, $payment->received_by);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->paymentStatus());

        $history = $order->statusHistories()->orderBy('id')->get();
        $this->assertNull($history[0]->from_status);
        $this->assertSame(PrintingOrderStatus::Accepted, $history[0]->to_status);
        $this->assertStringContainsString('Created at the counter', $history[0]->note);
        $this->assertSame($this->owner->id, $history[0]->changed_by);
        // Created (Accepted), the payment, then Accepted -> Processing
        $this->assertCount(3, $history);
        $this->assertSame(PrintingOrderStatus::Accepted, $history[2]->from_status);
        $this->assertSame(PrintingOrderStatus::Processing, $history[2]->to_status);
        $this->assertSame('Paid at the counter; printing starts.', $history[2]->note);
        $this->assertSame(0, DB::table('notifications')->count());

        // The success screen: the reference, the amount, the method and the change from 50.00
        $this->actingAs($this->owner)->get(route('owner.orders.walk-in.done', $order))->assertOk()
            ->assertSee($order->reference_number)->assertSee('₱36.00')->assertSee('Cash')->assertSee('₱14.00')->assertSee(route('owner.orders.show', $order));
    }

    public function test_a_custom_price_is_stored_with_who_set_it(): void
    {
        $this->send($this->payload(['price_choice' => 'custom', 'custom_price' => '30', 'price_note' => 'Suki discount']))->assertSessionHasNoErrors();

        $order = PrintingOrder::query()->sole();
        $this->assertSame('30.00', $order->confirmed_price);
        $this->assertSame('36.00', $order->estimated_price);
        $this->assertSame(['price_source' => 'custom', 'catalog_price' => '36.00', 'price' => '30.00', 'price_set_by' => $this->owner->id, 'price_note' => 'Suki discount'], $order->snapshot['counter']);
        $this->assertStringContainsString('set by the owner (catalog price ₱36.00): Suki discount', $order->statusHistories()->first()->note);
        $this->assertSame('30.00', Payment::query()->sole()->amount);
    }

    public function test_it_can_never_be_saved_unpaid_or_with_a_bad_payment(): void
    {
        $this->send($this->payload(['collected' => null]))->assertSessionHasErrors('collected');
        $this->send($this->payload(['method' => 'card']))->assertSessionHasErrors('method');
        $this->send($this->payload(['method' => 'gcash']))->assertSessionHasErrors('gcash_reference');
        $this->send($this->payload(['price_choice' => 'custom', 'custom_price' => '0']))->assertSessionHasErrors('custom_price');
        $this->send($this->payload(['price_choice' => 'custom', 'custom_price' => 'free']))->assertSessionHasErrors('custom_price');
        $this->send($this->payload(['new_customer_name' => '', 'customer_id' => null]))->assertSessionHasErrors('customer');
        $this->send($this->payload(['new_customer_phone' => 'call me']))->assertSessionHasErrors('new_customer_phone');
        $this->send($this->payload(['options' => []]))->assertSessionHasErrors('options.paper_size');

        $this->assertNothingSaved();

        $this->send($this->payload(['method' => 'gcash', 'gcash_reference' => 'GC-7788990']))->assertSessionHasNoErrors();
        $this->assertSame('GC-7788990', Payment::query()->sole()->gcash_reference);
    }

    public function test_a_quotation_service_needs_a_typed_price(): void
    {
        $quote = PrintingService::factory()->published()->quotation()->create(['allowed_file_types' => ['pdf'], 'max_file_size_mb' => 2]);

        $this->send($this->payload(['service' => $quote->id, 'options' => []]))->assertSessionHasErrors('price_choice');
        $this->assertNothingSaved();

        $this->send($this->payload(['service' => $quote->id, 'options' => [], 'price_choice' => 'custom', 'custom_price' => '450.50']))->assertSessionHasNoErrors();
        $this->assertSame('450.50', PrintingOrder::query()->sole()->confirmed_price);
    }

    public function test_a_failure_in_the_payment_step_leaves_nothing_behind(): void
    {
        Payment::creating(fn () => throw new RuntimeException('The disk is full.'));

        $this->withoutExceptionHandling();

        try {
            $this->send($this->payload(['files' => [$this->pdf()]]));
            $this->fail('The failure did not reach the test.');
        } catch (RuntimeException $e) {
            $this->assertSame('The disk is full.', $e->getMessage());
        }

        $this->assertNothingSaved();
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_double_submit_saves_one_order_and_one_payment(): void
    {
        $payload = $this->payload();

        $first = $this->send($payload);
        $second = $this->send($payload);

        $order = PrintingOrder::query()->sole();
        $first->assertRedirect(route('owner.orders.walk-in.done', $order));
        $second->assertRedirect(route('owner.orders.walk-in.done', $order));
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(1, User::query()->where('is_walk_in', true)->count());
    }

    public function test_an_existing_customer_is_reused_and_files_are_optional(): void
    {
        $known = User::factory()->walkIn()->create(['name' => 'Known Walk-in']);

        $this->send($this->payload(['customer_id' => $known->id, 'new_customer_name' => null, 'files' => [$this->pdf()]]))->assertSessionHasNoErrors();

        $order = PrintingOrder::query()->sole();
        $this->assertSame($known->id, $order->customer_id);
        $this->assertSame(1, $order->files()->count());
        $this->assertSame(1, User::query()->where('is_walk_in', true)->count());

        // An owner can never be the customer
        $this->send($this->payload(['customer_id' => $this->owner->id, 'new_customer_name' => null]))->assertSessionHasErrors('customer_id');
    }

    public function test_only_the_owner_reaches_the_counter(): void
    {
        $customer = User::factory()->create();
        $order = PrintingOrder::factory()->walkIn()->create();
        $routes = [
            ['get', route('owner.orders.walk-in.create')], ['post', route('owner.orders.walk-in.store')], ['get', route('owner.orders.walk-in.done', $order)],
            ['get', route('owner.orders.walk-in.price')], ['get', route('owner.walk-in.customers')],
        ];

        foreach ($routes as [$method, $url]) {
            $this->actingAs($customer)->{$method}($url, $this->payload())->assertForbidden();
        }
        auth()->logout();
        foreach ($routes as [$method, $url]) {
            $this->{$method}($url)->assertRedirect(route('login'));
        }
        $this->assertSame(1, PrintingOrder::query()->count());

        $this->actingAs($this->owner)->get(route('owner.orders.walk-in.create'))->assertOk()->assertSee('New walk-in order')->assertSee('Document Printing');
        // The success screen exists only for walk-in orders
        $online = PrintingOrder::factory()->create();
        $this->actingAs($this->owner)->get(route('owner.orders.walk-in.done', $online))->assertNotFound();
    }

    public function test_online_orders_stay_online_and_the_source_cannot_be_set(): void
    {
        $order = new PrintingOrder(['quantity' => 1, 'source' => 'walk_in']);
        $this->assertSame(RecordSource::Online, $order->source);

        $online = app(PrintingOrderService::class)->place(User::factory()->create(), $this->service, [$this->a4->id], 1, 1);
        $this->assertSame(RecordSource::Online, $online->fresh()->source);
    }

    public function test_the_lists_show_the_badge_and_filter_by_source(): void
    {
        $walkIn = PrintingOrder::factory()->walkIn()->create(['reference_number' => 'PR-700001']);
        PrintingOrder::factory()->create(['reference_number' => 'PR-700002']);

        $this->actingAs($this->owner)->get(route('owner.orders.index'))->assertOk()->assertSee('PR-700001')->assertSee('PR-700002')->assertSee('Walk-in')->assertSee('New walk-in order');
        $this->actingAs($this->owner)->get(route('owner.orders.index', ['source' => 'walk_in']))->assertOk()->assertSee('PR-700001')->assertDontSee('PR-700002');
        $this->actingAs($this->owner)->get(route('owner.orders.index', ['source' => 'online']))->assertOk()->assertDontSee('PR-700001')->assertSee('PR-700002');
        $this->actingAs($this->owner)->get(route('owner.orders.index', ['source' => 'robot']))->assertSessionHasErrors('source');
        $this->actingAs($this->owner)->get(route('owner.orders.show', $walkIn))->assertOk()->assertSee('Walk-in');
    }
}
