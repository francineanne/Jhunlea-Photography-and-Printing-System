<?php

namespace Tests\Feature\Catalog;

use App\Models\PhotographyPackage;
use App\Models\Theme;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SamplePackagesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SamplePackagesSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_runs_twice_without_duplicates_and_creates_no_users(): void
    {
        $this->seed(SamplePackagesSeeder::class);
        $counts = [PhotographyPackage::count(), Theme::count(), DB::table('package_theme')->count()];

        $this->seed(SamplePackagesSeeder::class);

        $this->assertSame([6, 6], array_slice($counts, 0, 2));
        $this->assertSame($counts, [PhotographyPackage::count(), Theme::count(), DB::table('package_theme')->count()]);
        $this->assertSame(0, User::count());
    }

    public function test_the_sample_data_has_the_agreed_mix(): void
    {
        $this->seed(SamplePackagesSeeder::class);

        $this->assertSame(5, PhotographyPackage::published()->count());
        $this->assertSame(2, PhotographyPackage::featured()->count());
        $this->assertSame(5, Theme::published()->count());
        $this->assertTrue(PhotographyPackage::whereNull('price')->exists());
        $this->assertTrue(PhotographyPackage::where('price_requires_confirmation', true)->whereNotNull('price')->exists());
        $this->assertTrue(PhotographyPackage::where('price_requires_confirmation', false)->whereNotNull('price')->exists());

        PhotographyPackage::with('themes')->get()->each(function ($package) {
            $this->assertGreaterThanOrEqual(2, $package->themes->count(), $package->name);
            $this->assertLessThanOrEqual(4, $package->themes->count(), $package->name);
            $this->assertGreaterThanOrEqual(3, count($package->inclusions), $package->name);
        });

        Theme::all()->each(function ($theme) {
            $this->assertGreaterThanOrEqual(3, count($theme->color_swatches));
            $this->assertLessThanOrEqual(6, count($theme->color_swatches));
            $this->assertNull($theme->image_path);
        });
    }

    public function test_running_it_again_keeps_the_owners_changes(): void
    {
        $this->seed(SamplePackagesSeeder::class);

        $edited = PhotographyPackage::where('slug', 'family-portrait')->firstOrFail();
        $edited->update(['price' => '9999.00', 'is_published' => false]);
        $edited->themes()->detach();
        PhotographyPackage::where('slug', 'couple-session')->firstOrFail()->delete();
        Theme::where('name', 'Soft White')->firstOrFail()->update(['name' => 'Soft White Renamed']);

        $this->seed(SamplePackagesSeeder::class);

        $this->assertSame('9999.00', $edited->fresh()->price);
        $this->assertFalse($edited->fresh()->is_published);
        $this->assertSame(0, $edited->themes()->count());
        $this->assertSoftDeleted('photography_packages', ['slug' => 'couple-session']);
        $this->assertSame(1, PhotographyPackage::withTrashed()->where('slug', 'couple-session')->count());
    }

    public function test_the_database_seeder_adds_it_only_in_the_local_environment(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->assertSame(0, PhotographyPackage::count());
        $this->assertSame(0, Theme::count());

        $this->app->detectEnvironment(fn () => 'local');
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(6, PhotographyPackage::count());
        $this->assertSame(6, Theme::count());
        $this->assertSame(0, User::count());
    }
}
