<?php

namespace Tests\Feature\Customer;

use App\Enums\OrderEvent;
use App\Enums\PrintingOrderStatus as Status;
use App\Models\OrderFile;
use App\Models\OrderStatusHistory;
use App\Models\PrintingOrder;
use App\Models\PrintingService;
use App\Models\User;
use App\Services\StatusTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MyOrdersTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    private User $alice;

    private User $bob;

    private PrintingService $service;

    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->alice = User::factory()->create(['name' => 'Alice Customer']);
        $this->bob = User::factory()->create(['name' => 'Bob Customer']);
        $this->service = PrintingService::factory()->published()->create(['name' => 'Document Printing', 'allowed_file_types' => ['pdf'], 'max_file_size_mb' => 2]);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function upload(string $name, string $contents = self::PDF): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }

    private function order(User $customer, Status $status = Status::Submitted, array $attributes = []): PrintingOrder
    {
        return PrintingOrder::factory()->forCustomer($customer)->status($status)->create(['printing_service_id' => $this->service->id] + $attributes);
    }

    // The list

    public function test_the_list_shows_only_my_orders_newest_first(): void
    {
        $older = $this->order($this->alice, attributes: ['reference_number' => 'PR-000001', 'created_at' => now()->subDay()]);
        $newer = $this->order($this->alice, attributes: ['reference_number' => 'PR-000003']);
        $this->order($this->bob, attributes: ['reference_number' => 'PR-000002']);

        $this->actingAs($this->alice)->get(route('customer.printing.index'))
            ->assertOk()
            ->assertSeeInOrder(['PR-000003', 'PR-000001'])
            ->assertDontSee('PR-000002');
    }

    public function test_the_status_filter_and_its_counts_use_only_my_orders(): void
    {
        $this->order($this->alice, Status::Submitted, ['reference_number' => 'PR-000011']);
        $this->order($this->alice, Status::Completed, ['reference_number' => 'PR-000012']);
        $this->order($this->bob, Status::Completed, ['reference_number' => 'PR-000013']);

        $response = $this->actingAs($this->alice)->get(route('customer.printing.index', ['status' => 'completed']))->assertOk();

        $response->assertSee('PR-000012')->assertDontSee('PR-000011')->assertDontSee('PR-000013');
        $this->assertSame(2, $response->viewData('counts')->sum());
        $this->assertSame(1, $response->viewData('counts')['completed']);
    }

    public function test_an_unknown_status_filter_sends_me_back_to_the_full_list(): void
    {
        $this->actingAs($this->alice)->get(route('customer.printing.index', ['status' => "x' OR 1=1 --"]))
            ->assertRedirect(route('customer.printing.index'))
            ->assertSessionHasErrors('status');

        $this->actingAs($this->alice)->get(route('customer.printing.index', ['status' => ['submitted']]))->assertSessionHasErrors('status');
    }

    public function test_the_list_shows_the_estimate_text_and_an_empty_state(): void
    {
        $this->actingAs($this->alice)->get(route('customer.printing.index'))->assertSee('You have no printing orders yet');

        $this->order($this->alice, attributes: ['estimated_price' => null]);
        $this->actingAs($this->alice)->get(route('customer.printing.index'))->assertSee('Price quoted after review');
    }

    public function test_the_list_is_paginated(): void
    {
        foreach (range(1, 12) as $i) {
            $this->order($this->alice, attributes: ['reference_number' => sprintf('PR-%06d', $i), 'created_at' => now()->subMinutes(20 - $i)]);
        }

        $this->actingAs($this->alice)->get(route('customer.printing.index'))->assertSee('PR-000012')->assertDontSee('PR-000002');
        $this->actingAs($this->alice)->get(route('customer.printing.index', ['page' => 2]))->assertSee('PR-000002')->assertDontSee('PR-000012');
    }

    // One order

    public function test_my_order_shows_its_details_files_and_timeline(): void
    {
        $order = $this->order($this->alice, Status::UnderReview, [
            'quantity' => 3,
            'notes' => 'Staple please.',
            'snapshot' => ['service' => ['name' => 'Document Printing', 'pricing_type' => 'per_page', 'base_price' => '5.00'], 'options' => [['id' => 1, 'group' => 'paper_size', 'label' => 'A4', 'price_modifier' => '1.00']], 'pages' => 7, 'unit_price' => '6.00'],
            'estimated_price' => '126.00',
            'confirmed_price' => null,
        ]);
        $file = OrderFile::factory()->create(['printing_order_id' => $order->id, 'uploaded_by' => $this->alice->id, 'original_name' => 'thesis.pdf']);
        OrderStatusHistory::factory()->create(['printing_order_id' => $order->id, 'changed_by' => $this->alice->id, 'to_status' => Status::Submitted]);
        $owner = User::factory()->owner()->create(['name' => 'Owner Secret Name', 'email' => 'owner-secret@example.test']);
        OrderStatusHistory::factory()->create(['printing_order_id' => $order->id, 'changed_by' => $owner->id, 'from_status' => Status::Submitted, 'to_status' => Status::UnderReview]);

        $this->actingAs($this->alice)->get(route('customer.printing.show', $order))
            ->assertOk()
            ->assertSee($order->reference_number)
            ->assertSee('Document Printing')
            ->assertSee('Paper size')->assertSee('A4')
            ->assertSee('Pages')->assertSee('7')
            ->assertSee('Staple please.')
            ->assertSee('₱126.00')
            ->assertSee('Not confirmed yet')
            ->assertSee('thesis.pdf')
            ->assertSee(route('files.download', $file), false)
            ->assertSee('Order placed')
            ->assertSee('You')
            ->assertSee('The shop')
            ->assertDontSee('Owner Secret Name')
            ->assertDontSee('owner-secret@example.test')
            ->assertDontSee($file->stored_name)
            ->assertDontSee($file->path);
    }

    public function test_the_confirmed_price_rejection_reason_and_info_request_are_shown(): void
    {
        $accepted = $this->order($this->alice, Status::Accepted, ['confirmed_price' => '250.00']);
        $rejected = $this->order($this->alice, Status::Rejected, ['rejection_reason' => 'The file is blurry.']);
        $asked = $this->order($this->alice, Status::UnderReview, ['info_request' => 'Please send a sharper copy.']);

        $this->actingAs($this->alice)->get(route('customer.printing.show', $accepted))->assertSee('₱250.00')->assertSee('Confirmed price');
        $this->actingAs($this->alice)->get(route('customer.printing.show', $rejected))->assertSee('The file is blurry.');
        $this->actingAs($this->alice)->get(route('customer.printing.show', $asked))->assertSee('Please send a sharper copy.')->assertSee('Action needed');
    }

    public function test_text_from_people_is_escaped(): void
    {
        $order = $this->order($this->alice, Status::UnderReview, ['notes' => '<script>alert(1)</script>', 'info_request' => '<img src=x onerror=alert(2)>']);
        OrderFile::factory()->create(['printing_order_id' => $order->id, 'uploaded_by' => $this->alice->id, 'original_name' => '"><svg onload=alert(3)>.pdf']);

        $html = $this->actingAs($this->alice)->get(route('customer.printing.show', $order))->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x onerror=alert(2)>', $html);
        $this->assertStringNotContainsString('<svg onload=alert(3)>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function test_customer_b_cannot_see_cancel_or_add_files_to_my_order(): void
    {
        $order = $this->order($this->alice);

        $this->actingAs($this->bob)->get(route('customer.printing.show', $order))->assertForbidden();
        $this->actingAs($this->bob)->get(route('customer.printing.placed', $order))->assertForbidden();
        $this->actingAs($this->bob)->post(route('customer.printing.cancel', $order))->assertForbidden();
        $this->actingAs($this->bob)->post(route('customer.printing.files.store', $order), ['files' => [$this->upload('x.pdf')]])->assertForbidden();

        $this->assertSame(Status::Submitted, $order->fresh()->status);
        $this->assertSame(0, OrderFile::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_an_order_id_that_does_not_exist_is_not_found(): void
    {
        $this->actingAs($this->alice)->get('/my/printing/999999')->assertNotFound();
    }

    public function test_the_owner_cannot_use_customer_pages(): void
    {
        $order = $this->order($this->alice);
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->get(route('customer.printing.index'))->assertForbidden();
        $this->actingAs($owner)->get(route('customer.printing.show', $order))->assertForbidden();
        $this->actingAs($owner)->post(route('customer.printing.cancel', $order))->assertForbidden();
    }

    // Cancel

    public function test_i_can_cancel_my_submitted_order_with_an_optional_reason(): void
    {
        $order = $this->order($this->alice);

        $this->actingAs($this->alice)->get(route('customer.printing.show', $order))->assertSee('Cancel this order');

        $this->actingAs($this->alice)->post(route('customer.printing.cancel', $order), ['reason' => 'Ordered twice by mistake.'])
            ->assertRedirect(route('customer.printing.show', $order))
            ->assertSessionHas('status');

        $this->assertSame(Status::Cancelled, $order->fresh()->status);
        $row = OrderStatusHistory::sole();
        $this->assertSame('Ordered twice by mistake.', $row->note);
        $this->assertSame($this->alice->id, $row->changed_by);
    }

    public function test_the_cancel_reason_is_limited_to_500_characters(): void
    {
        $order = $this->order($this->alice);

        $this->actingAs($this->alice)->post(route('customer.printing.cancel', $order), ['reason' => str_repeat('a', 501)])->assertSessionHasErrors('reason');

        $this->assertSame(Status::Submitted, $order->fresh()->status);
    }

    public function test_i_cannot_cancel_once_the_shop_started_and_the_button_is_hidden(): void
    {
        $order = $this->order($this->alice, Status::UnderReview);

        $this->actingAs($this->alice)->get(route('customer.printing.show', $order))->assertDontSee('Cancel this order');
        $this->actingAs($this->alice)->post(route('customer.printing.cancel', $order))->assertForbidden();

        $this->assertSame(Status::UnderReview, $order->fresh()->status);
    }

    public function test_cancelling_twice_writes_one_history_row(): void
    {
        $order = $this->order($this->alice);

        $this->actingAs($this->alice)->post(route('customer.printing.cancel', $order));
        $this->actingAs($this->alice)->post(route('customer.printing.cancel', $order))->assertForbidden();

        $this->assertSame(1, OrderStatusHistory::count());
    }

    // Adding files

    public function test_i_can_add_files_while_the_order_is_open_and_it_answers_the_shop(): void
    {
        $order = $this->order($this->alice, Status::UnderReview, ['info_request' => 'Please send page 2.']);

        $this->actingAs($this->alice)->post(route('customer.printing.files.store', $order), ['files' => [$this->upload('page-2.pdf')]])
            ->assertRedirect(route('customer.printing.show', $order).'#files')
            ->assertSessionHas('status');

        $this->assertSame(['page-2.pdf'], $order->files()->pluck('original_name')->all());
        Storage::disk('local')->assertExists($order->files()->first()->path);
        $this->assertNull($order->fresh()->info_request);
        $this->assertSame(OrderEvent::InfoAnswered, OrderStatusHistory::sole()->event);
    }

    public function test_added_files_follow_the_same_rules(): void
    {
        $order = $this->order($this->alice, Status::Submitted);

        $this->actingAs($this->alice)->post(route('customer.printing.files.store', $order), ['files' => [$this->upload('evil.pdf', '<?php echo 1;')]])->assertSessionHasErrors('files.0');
        $this->actingAs($this->alice)->post(route('customer.printing.files.store', $order), ['files' => [$this->upload('letter.docx')]])->assertSessionHasErrors('files.0');
        $this->actingAs($this->alice)->post(route('customer.printing.files.store', $order), ['files' => []])->assertSessionHasErrors('files');

        $this->assertSame(0, OrderFile::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_the_file_count_and_total_include_the_files_the_order_already_has(): void
    {
        $order = $this->order($this->alice, Status::Submitted);
        OrderFile::factory()->count(9)->create(['printing_order_id' => $order->id, 'uploaded_by' => $this->alice->id, 'size_bytes' => 1024]);

        $this->actingAs($this->alice)->post(route('customer.printing.files.store', $order), ['files' => [$this->upload('a.pdf'), $this->upload('b.pdf')]])
            ->assertSessionHasErrors(['files' => 'This order can take 1 more file.']);

        $full = $this->order($this->alice, Status::Submitted);
        OrderFile::factory()->create(['printing_order_id' => $full->id, 'uploaded_by' => $this->alice->id, 'size_bytes' => 30 * 1024 * 1024]);

        $this->actingAs($this->alice)->post(route('customer.printing.files.store', $full), ['files' => [$this->upload('c.pdf')]])
            ->assertSessionHasErrors('files');

        $this->assertSame(10, OrderFile::count());
    }

    public function test_files_cannot_be_added_once_the_order_is_accepted(): void
    {
        $order = $this->order($this->alice, Status::Accepted);

        $this->actingAs($this->alice)->get(route('customer.printing.show', $order))->assertDontSee('Add files');
        $this->actingAs($this->alice)->post(route('customer.printing.files.store', $order), ['files' => [$this->upload('late.pdf')]])->assertForbidden();
    }

    public function test_the_dashboard_and_the_placed_page_link_here(): void
    {
        $order = $this->order($this->alice, Status::UnderReview, ['info_request' => 'Need a PDF.']);

        $this->actingAs($this->alice)->get(route('customer.dashboard'))
            ->assertSee(route('customer.printing.show', $order), false)
            ->assertSee('The shop needs more information');

        $this->actingAs($this->alice)->get(route('customer.printing.placed', $order))
            ->assertSee(route('customer.printing.show', $order), false)
            ->assertSee(route('customer.printing.index'), false);
    }

    public function test_the_service_still_shows_after_the_owner_removes_it(): void
    {
        $order = $this->order($this->alice);
        $this->service->delete();

        $this->actingAs($this->alice)->get(route('customer.printing.show', $order))->assertOk();
        $this->actingAs($this->alice)->get(route('customer.printing.index'))->assertOk();
    }

    public function test_mass_assignment_from_the_cancel_form_cannot_change_price_or_owner(): void
    {
        $order = $this->order($this->alice);

        $this->actingAs($this->alice)->post(route('customer.printing.cancel', $order), [
            'confirmed_price' => '1.00', 'customer_id' => $this->bob->id, 'status' => 'completed',
        ]);

        $order->refresh();
        $this->assertSame(Status::Cancelled, $order->status);
        $this->assertNull($order->confirmed_price);
        $this->assertSame($this->alice->id, $order->customer_id);
    }

    public function test_a_service_change_through_the_service_layer_is_visible_on_the_page(): void
    {
        $order = $this->order($this->alice, Status::UnderReview);
        app(StatusTransitionService::class)->requestInfo($order, User::factory()->owner()->create(), 'Is page 3 in colour?');

        $this->actingAs($this->alice)->get(route('customer.printing.show', $order))
            ->assertSee('More information requested')
            ->assertSee('Is page 3 in colour?');
    }
}
