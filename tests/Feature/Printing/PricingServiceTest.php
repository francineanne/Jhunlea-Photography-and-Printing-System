<?php

namespace Tests\Feature\Printing;

use App\Enums\OptionGroup;
use App\Enums\PricingType;
use App\Models\PrintingService;
use App\Models\PrintingServiceOption;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PricingServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(string $type = 'per_page', ?string $base = '5.00'): PrintingService
    {
        return PrintingService::factory()->published()->create([
            'pricing_type' => PricingType::from($type),
            'base_price' => $base,
        ]);
    }

    private function option(PrintingService $service, string $modifier, OptionGroup $group = OptionGroup::PaperSize, bool $active = true): PrintingServiceOption
    {
        return PrintingServiceOption::factory()->create([
            'printing_service_id' => $service->id,
            'group' => $group,
            'price_modifier' => $modifier,
            'is_active' => $active,
        ]);
    }

    private function estimate(PrintingService $service, array $optionIds = [], int $quantity = 1, int $pages = 1)
    {
        return app(PricingService::class)->estimate($service, $optionIds, $quantity, $pages);
    }

    public function test_per_page_is_unit_price_times_pages_times_copies(): void
    {
        $service = $this->service('per_page', '5.00');
        $a4 = $this->option($service, '2.50');

        $estimate = $this->estimate($service, [$a4->id], quantity: 3, pages: 4);

        $this->assertSame('7.50', $estimate->unitPrice);
        $this->assertSame('90.00', $estimate->total);
        $this->assertSame(4, $estimate->pages);
    }

    public function test_per_copy_ignores_pages(): void
    {
        $service = $this->service('per_copy', '12.00');

        $estimate = $this->estimate($service, [], quantity: 5, pages: 40);

        $this->assertSame('60.00', $estimate->total);
        $this->assertNull($estimate->pages);
    }

    public function test_options_from_different_groups_add_up(): void
    {
        $service = $this->service('per_copy', '10.00');
        $glossy = $this->option($service, '3.00', OptionGroup::PaperType);
        $color = $this->option($service, '1.25', OptionGroup::ColorMode);

        $estimate = $this->estimate($service, [$glossy->id, $color->id], quantity: 2);

        $this->assertSame('14.25', $estimate->unitPrice);
        $this->assertSame('28.50', $estimate->total);
        $this->assertSame(['paper_type', 'color_mode'], array_column($estimate->options, 'group'));
    }

    public function test_a_quotation_service_has_no_estimate_even_with_a_starting_price(): void
    {
        $service = $this->service('quotation', '100.00');

        $estimate = $this->estimate($service, [], quantity: 3);

        $this->assertNull($estimate->total);
        $this->assertNull($estimate->unitPrice);
    }

    public function test_a_service_without_a_base_price_has_no_estimate(): void
    {
        $service = $this->service('per_page', null);

        $this->assertNull($this->estimate($service, [], quantity: 2, pages: 3)->total);
    }

    public function test_cents_that_floats_get_wrong_come_out_exact(): void
    {
        $service = $this->service('per_copy', '0.10');
        $extra = $this->option($service, '0.20');

        $this->assertSame('0.30', $this->estimate($service, [$extra->id])->unitPrice);

        $other = $this->service('per_copy', '19.99');
        $this->assertSame('59.97', $this->estimate($other, [], quantity: 3)->total);

        $third = $this->service('per_copy', '1.15');
        $this->assertSame('11.50', $this->estimate($third, [], quantity: 10)->total);
    }

    public function test_a_discount_option_never_pushes_the_unit_price_below_zero(): void
    {
        $service = $this->service('per_copy', '2.00');
        $discount = $this->option($service, '-5.00');

        $estimate = $this->estimate($service, [$discount->id], quantity: 4);

        $this->assertSame('0.00', $estimate->unitPrice);
        $this->assertSame('0.00', $estimate->total);
    }

    public function test_a_free_service_estimates_zero_not_nothing(): void
    {
        $this->assertSame('0.00', $this->estimate($this->service('per_copy', '0.00'), [], quantity: 7)->total);
    }

    public function test_the_same_option_sent_twice_counts_once(): void
    {
        $service = $this->service('per_copy', '10.00');
        $option = $this->option($service, '2.00');

        $this->assertSame('12.00', $this->estimate($service, [$option->id, (string) $option->id])->total);
    }

    public function test_an_option_of_another_service_is_refused(): void
    {
        $service = $this->service();
        $foreign = $this->option($this->service(), '1.00');

        $this->expectException(InvalidArgumentException::class);

        $this->estimate($service, [$foreign->id]);
    }

    public function test_an_option_that_does_not_exist_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->estimate($this->service(), [999999]);
    }

    public function test_a_switched_off_option_is_refused(): void
    {
        $service = $this->service();
        $off = $this->option($service, '1.00', active: false);

        $this->expectException(InvalidArgumentException::class);

        $this->estimate($service, [$off->id]);
    }

    public function test_two_options_from_one_group_are_refused(): void
    {
        $service = $this->service();
        $a4 = $this->option($service, '0.00');
        $a3 = $this->option($service, '5.00');

        $this->expectException(InvalidArgumentException::class);

        $this->estimate($service, [$a4->id, $a3->id]);
    }

    public static function badNumbers(): array
    {
        return [
            'zero copies' => [0, 1],
            'negative copies' => [-2, 1],
            'too many copies' => [10001, 1],
            'zero pages' => [1, 0],
            'negative pages' => [1, -1],
            'too many pages' => [1, 10001],
        ];
    }

    #[DataProvider('badNumbers')]
    public function test_quantity_and_pages_must_be_in_range(int $quantity, int $pages): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->estimate($this->service(), [], $quantity, $pages);
    }

    public function test_the_largest_total_the_database_can_hold_is_accepted_and_one_more_is_not(): void
    {
        $service = $this->service('per_copy', '99999999.99');

        $this->assertSame('99999999.99', $this->estimate($service, [], quantity: 1)->total);

        $this->expectException(InvalidArgumentException::class);

        $this->estimate($service, [], quantity: 2);
    }

    public function test_the_snapshot_keeps_the_service_and_the_options_as_they_were(): void
    {
        $service = $this->service('per_page', '5.00');
        $a4 = $this->option($service, '2.50');

        $snapshot = $this->estimate($service, [$a4->id], quantity: 2, pages: 3)->snapshot($service->name, 'per_page', '5.00');

        $this->assertSame('5.00', $snapshot['service']['base_price']);
        $this->assertSame('per_page', $snapshot['service']['pricing_type']);
        $this->assertSame('2.50', $snapshot['options'][0]['price_modifier']);
        $this->assertSame($a4->label, $snapshot['options'][0]['label']);
        $this->assertSame(3, $snapshot['pages']);
        $this->assertSame('7.50', $snapshot['unit_price']);
    }
}
