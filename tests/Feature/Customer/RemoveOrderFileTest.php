<?php

namespace Tests\Feature\Customer;

use App\Enums\OrderEvent;
use App\Enums\PrintingOrderStatus as Status;
use App\Models\OrderFile;
use App\Models\OrderStatusHistory;
use App\Models\PrintingOrder;
use App\Models\PrintingService;
use App\Models\User;
use App\Services\NotificationDispatcher;
use App\Services\PrintingOrderService;
use App\Services\StatusTransitionService;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class RemoveOrderFileTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    private User $alice;

    private User $owner;

    private PrintingService $service;

    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->alice = User::factory()->create();
        $this->owner = User::factory()->owner()->create(['name' => 'Shop Owner']);
        $this->service = PrintingService::factory()->published()->create(['allowed_file_types' => ['pdf'], 'max_file_size_mb' => 2]);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function order(Status $status = Status::UnderReview, ?User $customer = null, array $attributes = []): PrintingOrder
    {
        return PrintingOrder::factory()->forCustomer($customer ?? $this->alice)->status($status)->create(['printing_service_id' => $this->service->id] + $attributes);
    }

    /** A file row with a real file on the private disk. */
    private function file(PrintingOrder $order, ?User $uploader = null, string $name = 'page.pdf'): OrderFile
    {
        $stored = bin2hex(random_bytes(20)).'.pdf';
        $path = "printing-orders/{$order->id}/{$stored}";
        Storage::disk('local')->put($path, self::PDF);

        return OrderFile::factory()->create([
            'printing_order_id' => $order->id,
            'original_name' => $name,
            'stored_name' => $stored,
            'path' => $path,
            'size_bytes' => strlen(self::PDF),
            'uploaded_by' => ($uploader ?? $order->customer)->id,
        ]);
    }

    private function upload(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, self::PDF);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }

    private function remove(PrintingOrder $order, int $fileId, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->alice)->delete(route('customer.printing.files.destroy', ['order' => $order->id, 'file' => $fileId]));
    }

    public function test_i_can_remove_my_own_file(): void
    {
        $order = $this->order();
        $keep = $this->file($order, name: 'keep.pdf');
        $wrong = $this->file($order, name: 'wrong.pdf');

        $this->remove($order, $wrong->id)
            ->assertRedirect(route('customer.printing.show', $order).'#files')
            ->assertSessionHas('status');

        $this->assertModelMissing($wrong);
        $this->assertModelExists($keep);
        Storage::disk('local')->assertMissing($wrong->path);
        Storage::disk('local')->assertExists($keep->path);

        $row = OrderStatusHistory::sole();
        $this->assertSame(OrderEvent::FileRemoved, $row->event);
        $this->assertSame('Removed wrong.pdf', $row->note);
        $this->assertSame($this->alice->id, $row->changed_by);
        $this->assertSame(Status::UnderReview, $row->to_status);
    }

    public function test_it_works_from_a_plain_post_form_with_method_spoofing(): void
    {
        $order = $this->order(Status::Submitted);
        $this->file($order);
        $file = $this->file($order);

        $this->actingAs($this->alice)->post(route('customer.printing.files.destroy', ['order' => $order->id, 'file' => $file->id]), ['_method' => 'DELETE'])->assertRedirect();

        $this->assertModelMissing($file);
    }

    public function test_a_file_added_by_the_shop_cannot_be_removed_and_has_no_button(): void
    {
        $order = $this->order();
        $this->file($order);
        $shopFile = $this->file($order, $this->owner, 'from-the-shop.pdf');

        $this->actingAs($this->alice)->get(route('customer.printing.show', $order))->assertDontSee('Remove from-the-shop.pdf');
        $this->remove($order, $shopFile->id)->assertForbidden();

        $this->assertModelExists($shopFile);
        $this->assertSame(0, OrderStatusHistory::count());
    }

    public function test_another_customer_gets_403(): void
    {
        $order = $this->order();
        $this->file($order);
        $file = $this->file($order);

        $this->remove($order, $file->id, User::factory()->create())->assertForbidden();
        $this->remove($order, $file->id, $this->owner)->assertForbidden();

        $this->assertModelExists($file);
    }

    public function test_the_last_file_cannot_be_removed_and_has_no_button(): void
    {
        $order = $this->order();
        $only = $this->file($order, name: 'only.pdf');

        $this->actingAs($this->alice)->get(route('customer.printing.show', $order))->assertDontSee('Remove only.pdf');
        $this->remove($order, $only->id)->assertSessionHas('error', 'An order needs at least one file. Add the new file first, then remove the old one.');

        $this->assertModelExists($only);
        Storage::disk('local')->assertExists($only->path);
    }

    /** @return array<string, array{Status}> */
    public static function closedStatuses(): array
    {
        return collect([Status::Accepted, Status::Processing, Status::ReadyForPickup, Status::Completed, Status::Rejected, Status::Cancelled])
            ->mapWithKeys(fn (Status $status) => [$status->value => [$status]])->all();
    }

    #[DataProvider('closedStatuses')]
    public function test_an_order_past_review_refuses_and_shows_no_button(Status $status): void
    {
        $order = $this->order($status);
        $this->file($order);
        $file = $this->file($order, name: 'late.pdf');

        $this->actingAs($this->alice)->get(route('customer.printing.show', $order))->assertDontSee('Remove late.pdf');
        $this->remove($order, $file->id)->assertRedirect()->assertSessionHas('error');

        $this->assertModelExists($file);
    }

    public function test_a_page_loaded_before_the_shop_accepted_cannot_remove(): void
    {
        $order = $this->order();
        $this->file($order);
        $file = $this->file($order, name: 'stale.pdf');

        // The customer opens the page while the order is under review...
        $this->actingAs($this->alice)->get(route('customer.printing.show', $order))->assertSee('Remove stale.pdf');
        // ...then the shop accepts it before the customer presses Remove
        app(StatusTransitionService::class)->transition($order->fresh(), Status::Accepted, $this->owner, null, '100');

        $this->remove($order, $file->id)->assertSessionHas('error', 'This order is Accepted, so its files can no longer be changed. Please talk to the shop.');

        $this->assertModelExists($file);
    }

    public function test_a_double_submit_removes_once_and_answers_with_a_message(): void
    {
        $order = $this->order();
        $this->file($order);
        $file = $this->file($order);

        $this->remove($order, $file->id)->assertSessionHas('status');
        $this->remove($order, $file->id)
            ->assertRedirect(route('customer.printing.show', $order).'#files')
            ->assertSessionHas('error', 'That file is no longer on this order. The page may be out of date.');

        $this->assertSame(1, OrderFile::count());
        $this->assertSame(1, OrderStatusHistory::count());
    }

    public function test_a_file_of_another_order_is_refused(): void
    {
        $order = $this->order();
        $this->file($order);
        $this->file($order);
        $otherOrder = $this->order();
        $this->file($otherOrder);
        $foreign = $this->file($otherOrder, name: 'other-order.pdf');

        $this->remove($order, $foreign->id)->assertSessionHas('error');

        $this->assertModelExists($foreign);
        $this->assertSame(4, OrderFile::count());
    }

    public function test_a_file_of_another_customers_order_through_my_order_url_is_refused(): void
    {
        $mine = $this->order();
        $this->file($mine);
        $this->file($mine);
        $theirs = $this->order(customer: User::factory()->create());
        $this->file($theirs);
        $foreign = $this->file($theirs);

        $this->remove($mine, $foreign->id)->assertSessionHas('error');

        $this->assertModelExists($foreign);
    }

    public function test_removing_does_not_answer_the_shop_but_adding_does(): void
    {
        $order = $this->order(Status::UnderReview, attributes: ['info_request' => 'Chapter 2 is cut off. Please send it again.']);
        $this->file($order, name: 'chapter-1.pdf');
        $wrong = $this->file($order, name: 'chapter-2-cut.pdf');

        $this->remove($order, $wrong->id);
        $this->assertSame('Chapter 2 is cut off. Please send it again.', $order->fresh()->info_request);

        $this->actingAs($this->alice)->post(route('customer.printing.files.store', $order), ['files' => [$this->upload('chapter-2-fixed.pdf')]])->assertSessionHas('status');
        $this->assertNull($order->fresh()->info_request);

        $this->assertSame(
            [OrderEvent::FileRemoved, OrderEvent::InfoAnswered],
            OrderStatusHistory::orderBy('id')->get()->map(fn ($row) => $row->event)->all(),
        );
    }

    public function test_both_timelines_show_the_removal(): void
    {
        $order = $this->order();
        $this->file($order);
        $file = $this->file($order, name: 'old-cover.pdf');
        $this->remove($order, $file->id);

        $this->actingAs($this->alice)->get(route('customer.printing.show', $order))
            ->assertSee('File removed')->assertSee('Removed old-cover.pdf');

        $this->actingAs($this->owner)->get(route('owner.orders.show', $order))
            ->assertSee('File removed')->assertSee('Removed old-cover.pdf')->assertSee('Customer · ');
    }

    public function test_the_disk_file_is_deleted_only_after_the_save_commits(): void
    {
        $order = $this->order();
        $this->file($order);
        $file = $this->file($order);

        // The history row fails to save, so the whole removal is rolled back
        $this->app->instance(StatusTransitionService::class, new class(app(NotificationDispatcher::class)) extends StatusTransitionService
        {
            public function recordFileRemoved(PrintingOrder $order, User $actor, string $fileName): void
            {
                throw new RuntimeException('The database went away.');
            }
        });
        $this->app->forgetInstance(PrintingOrderService::class);
        $this->withoutExceptionHandling();

        try {
            $this->remove($order, $file->id);
            $this->fail('The removal should have failed.');
        } catch (RuntimeException $e) {
            $this->assertSame('The database went away.', $e->getMessage());
        }

        $this->assertModelExists($file);
        Storage::disk('local')->assertExists($file->path);
        $this->assertSame(0, OrderStatusHistory::count());
    }

    public function test_a_failed_disk_delete_is_reported_but_not_shown_to_the_customer(): void
    {
        $order = $this->order();
        $this->file($order);
        $file = $this->file($order);

        $reported = [];
        $this->app->make(ExceptionHandler::class)->reportable(function (Throwable $e) use (&$reported) {
            $reported[] = $e->getMessage();

            return false;
        });

        // The disk refuses to delete
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('delete')->once()->with($file->path)->andReturn(false);
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);

        $this->remove($order, $file->id)->assertSessionHas('status')->assertSessionMissing('error');

        $this->assertModelMissing($file);
        $this->assertCount(1, $reported);
        $this->assertStringContainsString('could not be deleted from the disk', $reported[0]);
    }

    public function test_hostile_file_names_are_escaped_in_the_note(): void
    {
        $order = $this->order();
        $this->file($order);
        $file = $this->file($order, name: '<img src=x onerror=alert(1)>.pdf');

        $this->remove($order, $file->id);

        $this->assertSame('Removed <img src=x onerror=alert(1)>.pdf', OrderStatusHistory::sole()->note);

        foreach ([route('customer.printing.show', $order) => $this->alice, route('owner.orders.show', $order) => $this->owner] as $url => $user) {
            $html = $this->actingAs($user)->get($url)->getContent();
            $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
            $this->assertStringContainsString('Removed &lt;img src=x onerror=alert(1)&gt;.pdf', $html);
        }
    }

    public function test_the_button_opens_the_existing_modal_and_the_full_order_text_changed(): void
    {
        $order = $this->order(Status::UnderReview);
        foreach (range(1, 10) as $i) {
            $this->file($order, name: "part-{$i}.pdf");
        }

        $html = $this->actingAs($this->alice)->get(route('customer.printing.show', $order))->getContent();

        $this->assertStringContainsString('This order has the most files it can hold. Remove one to add another.', $html);
        $this->assertSame(10, substr_count($html, 'aria-label="Remove part-'));
        $this->assertStringContainsString("\$dispatch('open-modal', 'remove-file')", $html);
        $this->assertStringContainsString('You can add it again while the shop is still reviewing.', $html);
        $this->assertStringContainsString('name="_method" value="DELETE"', $html);
        $this->assertStringNotContainsString('confirm(', $html);
    }

    public function test_the_route_only_takes_numbers(): void
    {
        $order = $this->order();

        // Nothing matches the delete route: 404, or 405 where a GET route has that address
        foreach (["/my/printing/{$order->id}/files/abc", "/my/printing/{$order->id}/files/..%2f..%2f.env", "/my/printing/{$order->id}/files/1%2f..%2f2"] as $url) {
            $this->assertContains($this->actingAs($this->alice)->delete($url)->getStatusCode(), [404, 405]);
        }
        $this->assertSame(0, OrderStatusHistory::count());
    }

    public function test_removing_is_rate_limited(): void
    {
        $order = $this->order();
        $this->file($order);

        for ($i = 0; $i < 10; $i++) {
            $this->remove($order, 999999);
        }

        $this->remove($order, 999999)->assertTooManyRequests();
    }
}
