<?php

namespace Tests\Feature\Owner;

use App\Enums\PricingType;
use App\Models\PrintingService;
use App\Models\PrintingServiceOption;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SampleCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SampleCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sample_seeder_runs_twice_without_duplicates_and_creates_no_users(): void
    {
        $this->seed(SampleCatalogSeeder::class);
        $services = PrintingService::count();
        $options = PrintingServiceOption::count();

        $this->seed(SampleCatalogSeeder::class);

        $this->assertGreaterThanOrEqual(6, $services);
        $this->assertSame($services, PrintingService::count());
        $this->assertSame($options, PrintingServiceOption::count());
        $this->assertSame(0, User::count());
    }

    public function test_running_the_seeder_again_keeps_the_owners_changes(): void
    {
        $this->seed(SampleCatalogSeeder::class);

        $edited = PrintingService::where('slug', 'document-printing')->firstOrFail();
        $edited->update(['base_price' => '99.00', 'is_published' => false]);
        $edited->options()->first()->delete();
        $remaining = $edited->options()->count();
        PrintingService::where('slug', 'photo-printing')->firstOrFail()->delete();

        $this->seed(SampleCatalogSeeder::class);

        $this->assertSame('99.00', $edited->fresh()->base_price);
        $this->assertFalse($edited->fresh()->is_published);
        $this->assertSame($remaining, $edited->options()->count());
        $this->assertSoftDeleted('printing_services', ['slug' => 'photo-printing']);
        $this->assertSame(1, PrintingService::withTrashed()->where('slug', 'photo-printing')->count());
    }

    public function test_the_sample_data_has_every_pricing_type_and_a_draft(): void
    {
        $this->seed(SampleCatalogSeeder::class);

        foreach (PricingType::cases() as $type) {
            $this->assertTrue(PrintingService::where('pricing_type', $type)->exists(), $type->value);
        }
        $this->assertTrue(PrintingService::where('is_published', false)->exists());
        $this->assertTrue(PrintingService::where('is_published', true)->exists());

        // Every sample service has between 2 and 4 option groups
        PrintingService::with('options')->get()->each(function ($service) {
            $groups = $service->options->groupBy(fn ($option) => $option->group->value)->count();
            $this->assertGreaterThanOrEqual(2, $groups, $service->name);
            $this->assertLessThanOrEqual(4, $groups, $service->name);
        });
    }

    public function test_the_database_seeder_skips_the_sample_catalog_outside_local(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, PrintingService::count());
        $this->assertSame(0, User::count());
    }

    public function test_the_database_seeder_adds_the_sample_catalog_in_local(): void
    {
        $this->app->detectEnvironment(fn () => 'local');

        $this->seed(DatabaseSeeder::class);

        $this->assertGreaterThanOrEqual(6, PrintingService::count());
        $this->assertSame(0, User::count());
    }

    public function test_money_is_formatted_in_one_place(): void
    {
        $this->assertSame('₱12.50', Money::format('12.50'));
        $this->assertSame('₱1,250.00', Money::format('1250'));
        $this->assertSame('—', Money::format(null));
        $this->assertSame('+₱5.00', Money::modifier('5'));
        $this->assertSame('No extra charge', Money::modifier('0.00'));
    }
}
