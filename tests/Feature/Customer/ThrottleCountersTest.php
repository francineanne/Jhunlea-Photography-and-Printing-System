<?php

namespace Tests\Feature\Customer;

use App\Enums\PrintingOrderStatus as Status;
use App\Models\OrderFile;
use App\Models\PrintingOrder;
use App\Models\PrintingService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Laravel keeps one counter per user for every unnamed throttle, so each customer route has a counter of its own.
 * Using one feature up to its limit must never block another.
 */
class ThrottleCountersTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    private User $customer;

    private PrintingService $service;

    private ?string $tempFile = null;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->customer = User::factory()->create();
        $this->service = PrintingService::factory()->published()->create(['allowed_file_types' => ['pdf'], 'max_file_size_mb' => 2, 'base_price' => '5.00']);
    }

    protected function tearDown(): void
    {
        if ($this->tempFile) {
            @unlink($this->tempFile);
        }

        parent::tearDown();
    }

    private function pdf(): UploadedFile
    {
        $this->tempFile = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($this->tempFile, self::PDF);

        return new UploadedFile($this->tempFile, 'thesis.pdf', null, null, true);
    }

    private function estimateUrl(): string
    {
        return route('customer.printing.estimate', ['service' => $this->service->id, 'quantity' => 1, 'pages' => 1]);
    }

    public function test_typing_in_the_wizard_never_blocks_sending_the_order(): void
    {
        // A customer who changes options a lot uses up the whole estimate limit...
        for ($i = 0; $i < 120; $i++) {
            $this->actingAs($this->customer)->getJson($this->estimateUrl())->assertOk();
        }
        $this->actingAs($this->customer)->getJson($this->estimateUrl())->assertTooManyRequests();

        // ...and can still open the wizard and send the order
        $this->actingAs($this->customer)->get(route('customer.printing.create'))->assertOk();
        $this->actingAs($this->customer)->post(route('customer.printing.store'), [
            'service' => $this->service->id, 'quantity' => 1, 'pages' => 1, 'files' => [$this->pdf()],
        ])->assertRedirect();

        $this->assertSame(1, PrintingOrder::count());
    }

    public function test_using_up_one_action_leaves_the_others_open(): void
    {
        $order = PrintingOrder::factory()->forCustomer($this->customer)->status(Status::UnderReview)->create(['printing_service_id' => $this->service->id]);
        OrderFile::factory()->count(2)->create(['printing_order_id' => $order->id, 'uploaded_by' => $this->customer->id]);

        // Ten refused cancels (the order is under review) use up the cancel limit
        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($this->customer)->post(route('customer.printing.cancel', $order))->assertForbidden();
        }
        $this->actingAs($this->customer)->post(route('customer.printing.cancel', $order))->assertTooManyRequests();

        // Every other customer action still answers normally
        $this->actingAs($this->customer)->post(route('customer.printing.files.store', $order), ['files' => [$this->pdf()]])->assertSessionHas('status');
        $file = $order->files()->first();
        $this->actingAs($this->customer)->delete(route('customer.printing.files.destroy', ['order' => $order->id, 'file' => $file->id]))->assertSessionHas('status');
        $this->actingAs($this->customer)->get(route('customer.printing.index'))->assertOk();
        $this->actingAs($this->customer)->get(route('customer.printing.show', $order))->assertOk();
        $this->actingAs($this->customer)->getJson($this->estimateUrl())->assertOk();
    }

    public function test_downloads_have_their_own_counter(): void
    {
        $order = PrintingOrder::factory()->forCustomer($this->customer)->status(Status::Submitted)->create(['printing_service_id' => $this->service->id]);
        Storage::disk('local')->put('printing-orders/x/a.pdf', self::PDF);
        $file = OrderFile::factory()->create(['printing_order_id' => $order->id, 'uploaded_by' => $this->customer->id, 'path' => 'printing-orders/x/a.pdf']);

        for ($i = 0; $i < 30; $i++) {
            $this->actingAs($this->customer)->get(route('files.download', $file))->assertOk();
        }
        $this->actingAs($this->customer)->get(route('files.download', $file))->assertTooManyRequests();

        $this->actingAs($this->customer)->get(route('customer.printing.show', $order))->assertOk();
        $this->actingAs($this->customer)->post(route('customer.printing.cancel', $order))->assertRedirect();
        $this->assertSame(Status::Cancelled, $order->fresh()->status);
    }

    public function test_every_customer_route_names_its_own_counter(): void
    {
        $names = collect(app('router')->getRoutes())
            ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'customer.printing.') || $route->getName() === 'files.download')
            ->mapWithKeys(fn ($route) => [$route->getName() => collect($route->gatherMiddleware())->first(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:'))]);

        $this->assertCount(10, $names);

        foreach ($names as $route => $throttle) {
            $this->assertNotNull($throttle, "{$route} has no throttle");
            $this->assertCount(3, explode(',', $throttle), "{$route} must name its own counter: {$throttle}");
        }

        $this->assertSame($names->count(), $names->map(fn ($t) => explode(',', $t)[2])->unique()->count(), 'Two routes share a counter');
    }
}
