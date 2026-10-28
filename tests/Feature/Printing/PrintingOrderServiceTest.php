<?php

namespace Tests\Feature\Printing;

use App\Enums\OptionGroup;
use App\Enums\PricingType;
use App\Enums\PrintingOrderStatus as Status;
use App\Models\OrderFile;
use App\Models\OrderStatusHistory;
use App\Models\PrintingOrder;
use App\Models\PrintingService;
use App\Models\PrintingServiceOption;
use App\Models\User;
use App\Services\PrintingOrderService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class PrintingOrderServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): PrintingService
    {
        return PrintingService::factory()->published()->create(['pricing_type' => PricingType::PerPage, 'base_price' => '5.00']);
    }

    private function place(User $customer, PrintingService $service, array $options = [], int $quantity = 1, int $pages = 1, ?string $notes = null): PrintingOrder
    {
        return app(PrintingOrderService::class)->place($customer, $service, $options, $quantity, $pages, $notes);
    }

    public function test_placing_an_order_saves_the_reference_estimate_snapshot_and_first_history_row(): void
    {
        $customer = User::factory()->create();
        $service = $this->service();
        $a4 = PrintingServiceOption::factory()->create(['printing_service_id' => $service->id, 'group' => OptionGroup::PaperSize, 'label' => 'A4', 'price_modifier' => '2.50']);

        $order = $this->place($customer, $service, [$a4->id], quantity: 2, pages: 3, notes: '  Staple the pages  ');
        $order = $order->fresh();

        $this->assertSame('PR-000001', $order->reference_number);
        $this->assertSame($customer->id, $order->customer_id);
        $this->assertSame($service->id, $order->printing_service_id);
        $this->assertSame(Status::Submitted, $order->status);
        $this->assertSame(2, $order->quantity);
        $this->assertSame('Staple the pages', $order->notes);
        $this->assertSame('45.00', $order->estimated_price);
        $this->assertNull($order->confirmed_price);
        $this->assertNull($order->rejection_reason);
        $this->assertNull($order->info_request);
        $this->assertSame('A4', $order->snapshot['options'][0]['label']);
        $this->assertSame('7.50', $order->snapshot['unit_price']);
        $this->assertSame(3, $order->snapshot['pages']);

        $history = $order->statusHistories;
        $this->assertCount(1, $history);
        $this->assertNull($history[0]->from_status);
        $this->assertSame(Status::Submitted, $history[0]->to_status);
        $this->assertSame($customer->id, $history[0]->changed_by);
    }

    public function test_orders_get_the_next_reference_number_each_time(): void
    {
        $customer = User::factory()->create();
        $service = $this->service();

        $this->assertSame('PR-000001', $this->place($customer, $service)->reference_number);
        $this->assertSame('PR-000002', $this->place($customer, $service)->reference_number);
    }

    public function test_the_snapshot_and_the_estimate_do_not_change_when_the_catalog_price_changes_later(): void
    {
        $service = $this->service();
        $option = PrintingServiceOption::factory()->create(['printing_service_id' => $service->id, 'group' => OptionGroup::Finish, 'label' => 'Matte', 'price_modifier' => '1.00']);
        $order = $this->place(User::factory()->create(), $service, [$option->id], quantity: 10);

        $service->update(['base_price' => '50.00', 'name' => 'Renamed']);
        $option->update(['price_modifier' => '9.00', 'label' => 'Renamed option']);

        $order = $order->fresh();
        $this->assertSame('60.00', $order->estimated_price);
        $this->assertSame('5.00', $order->snapshot['service']['base_price']);
        $this->assertSame('Matte', $order->snapshot['options'][0]['label']);
        $this->assertSame('1.00', $order->snapshot['options'][0]['price_modifier']);
    }

    public function test_a_quotation_order_has_no_estimate_and_no_confirmed_price(): void
    {
        $service = PrintingService::factory()->published()->quotation()->create();

        $order = $this->place(User::factory()->create(), $service, quantity: 4)->fresh();

        $this->assertNull($order->estimated_price);
        $this->assertNull($order->confirmed_price);
    }

    public function test_a_bad_option_saves_nothing_and_gives_the_reference_number_back(): void
    {
        $customer = User::factory()->create();
        $service = $this->service();

        try {
            $this->place($customer, $service, [999999]);
            $this->fail('The order should not have been placed.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame(0, PrintingOrder::count());
        $this->assertSame(0, OrderStatusHistory::count());
        $this->assertSame('PR-000001', $this->place($customer, $service)->reference_number);
    }

    public function test_the_owner_cannot_place_an_order(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->place(User::factory()->owner()->create(), $this->service());
    }

    public function test_an_unpublished_or_removed_service_cannot_be_ordered(): void
    {
        $customer = User::factory()->create();

        try {
            $this->place($customer, PrintingService::factory()->create());
            $this->fail('An unpublished service must be refused.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $removed = $this->service();
        $removed->delete();

        $this->expectException(InvalidArgumentException::class);

        $this->place($customer, $removed);
    }

    public function test_the_relationships_work_and_an_order_keeps_a_removed_service(): void
    {
        $customer = User::factory()->create();
        $service = $this->service();
        $order = $this->place($customer, $service);
        OrderFile::factory()->create(['printing_order_id' => $order->id, 'uploaded_by' => $customer->id]);

        $service->delete();
        $order = $order->fresh();

        $this->assertTrue($order->customer->is($customer));
        $this->assertTrue($order->service->is($service));
        $this->assertCount(1, $order->files);
        $this->assertTrue($order->files->first()->order->is($order));
        $this->assertTrue($order->files->first()->uploader->is($customer));
        $this->assertTrue($order->statusHistories->first()->order->is($order));
        $this->assertTrue($order->statusHistories->first()->changedBy->is($customer));
    }

    public function test_orders_are_soft_deleted(): void
    {
        $order = $this->place(User::factory()->create(), $this->service());

        $order->delete();

        $this->assertNull(PrintingOrder::find($order->id));
        $this->assertNotNull(PrintingOrder::withTrashed()->find($order->id));
    }

    public function test_history_rows_come_back_in_the_order_they_were_written(): void
    {
        $order = PrintingOrder::factory()->create();
        $user = User::factory()->create();

        foreach ([Status::Submitted, Status::UnderReview, Status::Accepted] as $status) {
            OrderStatusHistory::factory()->create(['printing_order_id' => $order->id, 'to_status' => $status, 'changed_by' => $user->id]);
        }

        $this->assertSame(['submitted', 'under_review', 'accepted'], $order->statusHistories->map(fn ($row) => $row->to_status->value)->all());
    }

    public function test_a_history_row_cannot_be_filled_from_a_request(): void
    {
        $this->expectException(MassAssignmentException::class);

        (new OrderStatusHistory)->fill(['to_status' => 'completed']);
    }

    public function test_the_tables_have_the_columns_the_phase_needs(): void
    {
        $this->assertTrue(Schema::hasColumns('printing_orders', [
            'id', 'reference_number', 'customer_id', 'printing_service_id', 'snapshot', 'quantity', 'notes', 'status',
            'estimated_price', 'confirmed_price', 'rejection_reason', 'info_request', 'created_at', 'updated_at', 'deleted_at',
        ]));
        $this->assertTrue(Schema::hasColumns('order_files', [
            'id', 'printing_order_id', 'original_name', 'stored_name', 'path', 'mime', 'size_bytes', 'uploaded_by',
        ]));
        $this->assertTrue(Schema::hasColumns('order_status_histories', [
            'id', 'printing_order_id', 'from_status', 'to_status', 'changed_by', 'note', 'created_at',
        ]));
        $this->assertTrue(Schema::hasColumns('reference_counters', ['prefix', 'last_number']));
    }

    public function test_money_columns_are_declared_as_exact_decimals_not_floats(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_10_30_100100_create_printing_orders_table.php'));

        $this->assertStringContainsString("decimal('estimated_price', 10, 2)", $migration);
        $this->assertStringContainsString("decimal('confirmed_price', 10, 2)", $migration);
        $this->assertStringNotContainsString('float', $migration);
        $this->assertStringNotContainsString('double', $migration);
    }
}
