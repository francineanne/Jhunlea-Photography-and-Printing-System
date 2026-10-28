<?php

namespace Tests\Feature\Printing;

use App\Models\PrintingOrder;
use App\Services\ReferenceNumberService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class ReferenceNumberServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ReferenceNumberService
    {
        return app(ReferenceNumberService::class);
    }

    public function test_numbers_start_at_one_and_count_up_with_six_digits(): void
    {
        $this->assertSame('PR-000001', $this->service()->next());
        $this->assertSame('PR-000002', $this->service()->next());
        $this->assertSame('PR-000003', $this->service()->next());
    }

    public function test_every_prefix_counts_on_its_own(): void
    {
        $this->assertSame('PR-000001', $this->service()->next('PR'));
        $this->assertSame('PB-000001', $this->service()->next('PB'));
        $this->assertSame('PR-000002', $this->service()->next('PR'));
        $this->assertSame('PB-000002', $this->service()->next('PB'));
    }

    public function test_a_long_run_never_repeats_a_number(): void
    {
        $numbers = collect(range(1, 300))->map(fn () => $this->service()->next());

        $this->assertCount(300, $numbers->unique());
        $this->assertSame('PR-000300', $numbers->last());
    }

    public function test_the_number_grows_past_six_digits_without_being_cut_off(): void
    {
        DB::table('reference_counters')->insert(['prefix' => 'PR', 'last_number' => 999999]);

        $this->assertSame('PR-1000000', $this->service()->next());
    }

    public function test_a_number_taken_inside_a_failed_transaction_is_given_back(): void
    {
        try {
            DB::transaction(function () {
                $this->assertSame('PR-000001', $this->service()->next());

                throw new RuntimeException('The order failed to save.');
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame('PR-000001', $this->service()->next());
    }

    public function test_numbers_taken_inside_one_transaction_stay_in_order(): void
    {
        $taken = DB::transaction(fn () => [$this->service()->next(), $this->service()->next()]);

        $this->assertSame(['PR-000001', 'PR-000002'], $taken);
        $this->assertSame('PR-000003', $this->service()->next());
    }

    public function test_the_database_refuses_a_repeated_reference_number_on_orders(): void
    {
        PrintingOrder::factory()->create(['reference_number' => 'PR-000001']);

        $this->expectException(QueryException::class);

        PrintingOrder::factory()->create(['reference_number' => 'PR-000001']);
    }
}
