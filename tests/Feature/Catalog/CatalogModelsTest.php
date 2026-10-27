<?php

namespace Tests\Feature\Catalog;

use App\Models\PackageImage;
use App\Models\PhotographyPackage;
use App\Models\Theme;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CatalogModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_published_and_featured_scopes_filter_and_ignore_soft_deleted_rows(): void
    {
        PhotographyPackage::factory()->published()->featured()->create(['name' => 'Both']);
        PhotographyPackage::factory()->published()->create(['name' => 'Published only']);
        PhotographyPackage::factory()->featured()->create(['name' => 'Featured only']);
        PhotographyPackage::factory()->published()->featured()->create(['name' => 'Deleted'])->delete();
        Theme::factory()->published()->create();
        Theme::factory()->create();

        $this->assertEqualsCanonicalizing(['Both', 'Published only'], PhotographyPackage::published()->pluck('name')->all());
        $this->assertEqualsCanonicalizing(['Both', 'Featured only'], PhotographyPackage::featured()->pluck('name')->all());
        $this->assertSame(1, Theme::published()->count());
    }

    public function test_the_price_text_covers_the_three_cases(): void
    {
        $confirmed = PhotographyPackage::factory()->make(['price' => '1850.00', 'price_requires_confirmation' => true]);
        $plain = PhotographyPackage::factory()->make(['price' => '1200.5', 'price_requires_confirmation' => false]);
        $none = PhotographyPackage::factory()->make(['price' => null, 'price_requires_confirmation' => false]);
        $noneButFlagged = PhotographyPackage::factory()->make(['price' => null, 'price_requires_confirmation' => true]);

        $this->assertSame('From ₱1,850.00 — final price confirmed after review', $confirmed->priceText());
        $this->assertSame('₱1,200.50', $plain->priceText());
        $this->assertSame('Price on request', $none->priceText());
        $this->assertSame('Price on request', $noneButFlagged->priceText());
    }

    public function test_money_and_lists_are_stored_exactly(): void
    {
        $package = PhotographyPackage::factory()->create(['price' => '1850', 'inclusions' => ['One', 'Two']]);

        $this->assertSame('1850.00', $package->fresh()->price);
        $this->assertSame(['One', 'Two'], $package->fresh()->inclusions);
    }

    public function test_slugs_are_unique_and_a_deleted_package_keeps_its_slug_reserved(): void
    {
        $first = PhotographyPackage::factory()->create(['slug' => 'family-warmth']);
        $first->delete();

        $this->assertSame('family-warmth-2', PhotographyPackage::uniqueSlug('Family Warmth'));
        $this->assertSame('family-warmth', PhotographyPackage::uniqueSlug('Family Warmth', $first->id));

        $this->expectException(QueryException::class);
        PhotographyPackage::factory()->create(['slug' => 'family-warmth']);
    }

    public function test_relations_link_images_and_themes_both_ways(): void
    {
        $package = PhotographyPackage::factory()->create();
        $theme = Theme::factory()->create();
        $package->themes()->attach($theme);
        PackageImage::factory()->create(['photography_package_id' => $package->id, 'is_cover' => true, 'sort_order' => 2]);
        PackageImage::factory()->create(['photography_package_id' => $package->id, 'sort_order' => 1]);

        $this->assertSame([$theme->id], $package->themes()->pluck('themes.id')->all());
        $this->assertSame([$package->id], $theme->packages()->pluck('photography_packages.id')->all());
        $this->assertCount(2, $package->images);
        $this->assertSame([1, 2], $package->images->pluck('sort_order')->all());
        $this->assertTrue($package->coverImage->is_cover);
    }

    public function test_a_package_cannot_be_linked_to_the_same_theme_twice(): void
    {
        $package = PhotographyPackage::factory()->create();
        $theme = Theme::factory()->create();
        $package->themes()->attach($theme);

        $this->expectException(QueryException::class);
        $package->themes()->attach($theme);
    }

    public function test_deleting_a_package_row_removes_its_image_rows_and_links(): void
    {
        $package = PhotographyPackage::factory()->create();
        PackageImage::factory()->create(['photography_package_id' => $package->id]);
        $package->themes()->attach(Theme::factory()->create());

        $package->forceDelete();

        $this->assertSame(0, PackageImage::count());
        $this->assertSame(0, DB::table('package_theme')->count());
    }

    public function test_the_mass_assignment_guard_keeps_server_fields_out_of_requests(): void
    {
        $image = new PackageImage;
        $image->fill(['alt_text' => 'Alt', 'path' => 'hacked.php', 'is_cover' => true, 'photography_package_id' => 99]);
        $theme = new Theme;
        $theme->fill(['name' => 'T', 'image_path' => 'hacked.php']);

        $this->assertSame('Alt', $image->alt_text);
        $this->assertNull($image->path);
        $this->assertNull($image->photography_package_id);
        $this->assertNull($theme->image_path);
    }

    public function test_every_new_migration_can_be_reverted_and_applied_again(): void
    {
        $migrations = [
            '2026_10_29_100300_create_package_theme_table' => 'package_theme',
            '2026_10_29_100200_create_themes_table' => 'themes',
            '2026_10_29_100100_create_package_images_table' => 'package_images',
            '2026_10_29_100000_create_photography_packages_table' => 'photography_packages',
        ];

        foreach ($migrations as $file => $table) {
            $migration = require database_path("migrations/{$file}.php");

            $this->assertTrue(Schema::hasTable($table), $table);
            $migration->down();
            $this->assertFalse(Schema::hasTable($table), "{$table} should be dropped by down()");
        }

        foreach (array_reverse(array_keys($migrations)) as $file) {
            (require database_path("migrations/{$file}.php"))->up();
        }

        $this->assertTrue(Schema::hasTable('photography_packages') && Schema::hasTable('package_theme'));
    }
}
