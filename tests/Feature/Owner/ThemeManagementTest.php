<?php

namespace Tests\Feature\Owner;

use App\Models\PhotographyPackage;
use App\Models\Theme;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ThemeManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    /** @return array<string, mixed> */
    private function validData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Classic Crimson',
            'description' => 'A deep red backdrop.',
            'color_swatches' => ['#c62828', '#8e0000', '#171717'],
            'is_published' => '1',
        ], $overrides);
    }

    private function owner(): User
    {
        return User::factory()->owner()->create();
    }

    /** A real temporary file, so the type is sniffed from its content like a real upload (the fake one guesses from the name). */
    private function realFile(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'th');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    /** @return array<int, string> */
    private function storedFiles(): array
    {
        return Storage::disk('public')->allFiles();
    }

    // ---- access control -------------------------------------------------

    /** @return array<string, array{string, string}> */
    public static function routes(): array
    {
        return [
            'index' => ['get', '/owner/themes'],
            'create' => ['get', '/owner/themes/create'],
            'store' => ['post', '/owner/themes'],
            'edit' => ['get', '/owner/themes/1/edit'],
            'update' => ['put', '/owner/themes/1'],
            'destroy' => ['delete', '/owner/themes/1'],
            'publish' => ['post', '/owner/themes/1/publish'],
        ];
    }

    #[DataProvider('routes')]
    public function test_a_guest_is_sent_to_the_login_page(string $method, string $url): void
    {
        $this->{$method}($url)->assertRedirect('/login');
    }

    #[DataProvider('routes')]
    public function test_a_customer_gets_403_even_for_ids_that_do_not_exist(string $method, string $url): void
    {
        $this->actingAs(User::factory()->create())->{$method}($url)->assertForbidden();
    }

    public function test_the_sidebar_marks_themes_as_current(): void
    {
        $this->actingAs($this->owner())->get('/owner/themes')->assertOk()->assertSee('aria-current="page"', false);
    }

    // ---- create and edit ------------------------------------------------

    public function test_the_owner_can_create_a_theme_with_colors_and_packages(): void
    {
        $packages = PhotographyPackage::factory()->count(2)->create();

        $response = $this->actingAs($this->owner())->post('/owner/themes', $this->validData(['package_ids' => $packages->pluck('id')->all()]));

        $theme = Theme::firstOrFail();
        $response->assertRedirect("/owner/themes/{$theme->id}/edit");
        $this->assertSame(['#C62828', '#8E0000', '#171717'], $theme->color_swatches, 'colors are kept as #RRGGBB in upper case');
        $this->assertTrue($theme->is_published);
        $this->assertNull($theme->image_path);
        $this->assertEqualsCanonicalizing($packages->pluck('id')->all(), $theme->packages()->pluck('photography_packages.id')->all());
    }

    public function test_empty_color_boxes_are_dropped_and_a_theme_may_have_no_colors(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post('/owner/themes', $this->validData(['color_swatches' => ['#aaaaaa', '', null, '  ', '#BBBBBB', '', '']]))->assertSessionHasNoErrors();
        $this->actingAs($owner)->post('/owner/themes', $this->validData(['name' => 'Plain', 'color_swatches' => ['', '', '']]))->assertSessionHasNoErrors();

        $this->assertSame(['#AAAAAA', '#BBBBBB'], Theme::where('name', 'Classic Crimson')->firstOrFail()->color_swatches);
        $this->assertSame([], Theme::where('name', 'Plain')->firstOrFail()->color_swatches);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalid(): array
    {
        return [
            'missing name' => [['name' => ''], 'name'],
            'name too long' => [['name' => str_repeat('a', 81)], 'name'],
            'description too long' => [['description' => str_repeat('a', 1001)], 'description'],
            'seven colors' => [['color_swatches' => ['#111111', '#222222', '#333333', '#444444', '#555555', '#666666', '#777777']], 'color_swatches'],
            'color words' => [['color_swatches' => ['red']], 'color_swatches.0'],
            'short hex' => [['color_swatches' => ['#12345']], 'color_swatches.0'],
            'long hex' => [['color_swatches' => ['#1234567']], 'color_swatches.0'],
            'non hex letters' => [['color_swatches' => ['#GGGGGG']], 'color_swatches.0'],
            'no hash' => [['color_swatches' => ['C62828']], 'color_swatches.0'],
            'css injection' => [['color_swatches' => ['#111111;background:url(x)']], 'color_swatches.0'],
            'colors not a list' => [['color_swatches' => 'red'], 'color_swatches'],
            'color as array' => [['color_swatches' => [['#111111']]], 'color_swatches.0'],
            'unknown package id' => [['package_ids' => [9999]], 'package_ids.0'],
            'package id not a number' => [['package_ids' => ['x']], 'package_ids.0'],
            'packages not a list' => [['package_ids' => 'abc'], 'package_ids'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalid')]
    public function test_bad_input_is_rejected_and_nothing_is_saved(array $overrides, string $field): void
    {
        $this->actingAs($this->owner())->post('/owner/themes', $this->validData($overrides))->assertSessionHasErrors($field);

        $this->assertSame(0, Theme::count());
    }

    public function test_a_soft_deleted_packages_id_is_not_accepted(): void
    {
        $package = PhotographyPackage::factory()->create();
        $package->delete();

        $this->actingAs($this->owner())->post('/owner/themes', $this->validData(['package_ids' => [$package->id]]))->assertSessionHasErrors('package_ids.0');
    }

    public function test_the_form_keeps_what_was_typed_after_an_error(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->from('/owner/themes/create')->post('/owner/themes', $this->validData(['name' => 'Kept Name', 'color_swatches' => ['#111111', 'bad']]))
            ->assertRedirect('/owner/themes/create');

        $this->actingAs($owner)->get('/owner/themes/create')->assertSee('Kept Name')->assertSee('#111111');
    }

    public function test_the_image_path_cannot_be_forced_through_the_request(): void
    {
        $this->actingAs($this->owner())->post('/owner/themes', $this->validData(['image_path' => '../../evil.php', 'id' => 999]));

        $theme = Theme::firstOrFail();
        $this->assertNull($theme->image_path);
        $this->assertNotSame(999, $theme->id);
    }

    public function test_the_owner_can_edit_a_theme_and_change_its_packages_from_this_side(): void
    {
        [$keep, $drop, $add] = PhotographyPackage::factory()->count(3)->create()->all();
        $theme = Theme::factory()->create(['name' => 'Old']);
        $theme->packages()->sync([$keep->id, $drop->id]);

        $this->actingAs($this->owner())
            ->put("/owner/themes/{$theme->id}", $this->validData(['name' => 'Renamed', 'is_published' => '0', 'package_ids' => [$keep->id, $add->id]]))
            ->assertRedirect("/owner/themes/{$theme->id}/edit");

        $theme->refresh();
        $this->assertSame('Renamed', $theme->name);
        $this->assertFalse($theme->is_published);
        $this->assertEqualsCanonicalizing([$keep->id, $add->id], $theme->packages()->pluck('photography_packages.id')->all());
        $this->assertEqualsCanonicalizing([$theme->id], $keep->themes()->pluck('themes.id')->all());
        $this->assertSame(0, $drop->themes()->count());
    }

    public function test_saving_without_packages_removes_the_links_and_the_edit_page_shows_the_theme(): void
    {
        $theme = Theme::factory()->create(['name' => 'Edit Me', 'color_swatches' => ['#ABCDEF']]);
        $theme->packages()->attach(PhotographyPackage::factory()->create());
        $owner = $this->owner();

        $this->actingAs($owner)->get("/owner/themes/{$theme->id}/edit")->assertOk()->assertSee('Edit Me')->assertSee('#ABCDEF')->assertSee('Customer view preview');

        $this->actingAs($owner)->put("/owner/themes/{$theme->id}", $this->validData());

        $this->assertSame(0, DB::table('package_theme')->count());
    }

    public function test_publish_toggles_and_keeps_the_filters(): void
    {
        $theme = Theme::factory()->create();
        $owner = $this->owner();

        $this->actingAs($owner)->from('/owner/themes?search=a')->post("/owner/themes/{$theme->id}/publish")->assertRedirect('/owner/themes?search=a');
        $this->assertTrue($theme->fresh()->is_published);

        $this->actingAs($owner)->post("/owner/themes/{$theme->id}/publish");
        $this->assertFalse($theme->fresh()->is_published);
    }

    public function test_deleting_is_a_soft_delete_that_removes_the_theme_from_packages_pages_and_keeps_the_photo(): void
    {
        $theme = Theme::factory()->create(['name' => 'Gone Theme', 'image_path' => 'theme-images/keep.jpg']);
        Storage::disk('public')->put('theme-images/keep.jpg', 'x');
        $package = PhotographyPackage::factory()->create();
        $package->themes()->attach($theme);
        $owner = $this->owner();

        $this->actingAs($owner)->delete("/owner/themes/{$theme->id}")->assertRedirect('/owner/themes');

        $this->assertSoftDeleted('themes', ['id' => $theme->id]);
        Storage::disk('public')->assertExists('theme-images/keep.jpg');
        $this->actingAs($owner)->get("/owner/themes/{$theme->id}/edit")->assertNotFound();
        $this->actingAs($owner)->get('/owner/themes')->assertDontSee('Gone Theme');
        $this->actingAs($owner)->get("/owner/packages/{$package->id}/edit")->assertDontSee('Gone Theme');
        $this->assertSame(0, $package->themes()->count(), 'a soft-deleted theme no longer shows on the package');
    }

    // ---- photo ----------------------------------------------------------

    public function test_a_photo_is_stored_under_a_generated_name(): void
    {
        $this->actingAs($this->owner())->post('/owner/themes', $this->validData(['image' => UploadedFile::fake()->image('My Backdrop.jpg')]))->assertSessionHasNoErrors();

        $path = Theme::firstOrFail()->image_path;
        Storage::disk('public')->assertExists($path);
        $this->assertMatchesRegularExpression('#^theme-images/[A-Za-z0-9]{40}\.jpg$#', $path);
        $this->assertStringNotContainsStringIgnoringCase('backdrop', $path);
    }

    /** @return array<string, array{string, string}> */
    public static function badFiles(): array
    {
        return [
            'svg with a script' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            'php renamed to jpg' => ['shell.jpg', '<?php system($_GET["c"]); ?>'],
            'php file' => ['shell.php', '<?php echo 1;'],
            'pdf' => ['paper.pdf', "%PDF-1.4\n%%EOF"],
        ];
    }

    #[DataProvider('badFiles')]
    public function test_files_that_are_not_real_photos_are_rejected(string $name, string $content): void
    {
        $this->actingAs($this->owner())->post('/owner/themes', $this->validData(['image' => $this->realFile($name, $content)]))->assertSessionHasErrors('image');

        $this->assertSame([], $this->storedFiles());
        $this->assertSame(0, Theme::count());
    }

    public function test_an_oversize_photo_and_a_php_size_failure_are_rejected_with_plain_messages(): void
    {
        $owner = $this->owner();
        $path = tempnam(sys_get_temp_dir(), 'big');
        $tooBig = new UploadedFile($path, 'huge.jpg', 'image/jpeg', UPLOAD_ERR_INI_SIZE, true);

        $this->actingAs($owner)->post('/owner/themes', $this->validData(['image' => UploadedFile::fake()->image('big.jpg')->size(5121)]))->assertSessionHasErrors('image');
        $this->actingAs($owner)->post('/owner/themes', $this->validData(['image' => $tooBig]))
            ->assertSessionHasErrors(['image' => 'huge.jpg is bigger than this server accepts ('.ini_get('upload_max_filesize').' per file).']);

        $this->assertSame([], $this->storedFiles());
        @unlink($path);
    }

    public function test_a_theme_photo_with_a_huge_pixel_size_is_rejected(): void
    {
        $this->actingAs($this->owner())->post('/owner/themes', $this->validData(['image' => UploadedFile::fake()->image('wide.png', 8001, 10)]))->assertSessionHasErrors('image');

        $this->assertSame([], $this->storedFiles());
    }

    public function test_replacing_the_photo_deletes_the_old_file_and_removing_it_clears_the_path(): void
    {
        $theme = Theme::factory()->create(['image_path' => 'theme-images/old.jpg']);
        Storage::disk('public')->put('theme-images/old.jpg', 'x');
        $owner = $this->owner();

        $this->actingAs($owner)->put("/owner/themes/{$theme->id}", $this->validData(['image' => UploadedFile::fake()->image('new.jpg')]))->assertSessionHasNoErrors();

        $newPath = $theme->fresh()->image_path;
        $this->assertNotSame('theme-images/old.jpg', $newPath);
        Storage::disk('public')->assertMissing('theme-images/old.jpg');
        Storage::disk('public')->assertExists($newPath);

        $this->actingAs($owner)->put("/owner/themes/{$theme->id}", $this->validData(['remove_image' => '1']));

        $this->assertNull($theme->fresh()->image_path);
        Storage::disk('public')->assertMissing($newPath);
        $this->assertSame([], $this->storedFiles());
    }

    public function test_saving_without_a_new_photo_keeps_the_current_one(): void
    {
        $theme = Theme::factory()->create(['image_path' => 'theme-images/stay.jpg']);
        Storage::disk('public')->put('theme-images/stay.jpg', 'x');

        $this->actingAs($this->owner())->put("/owner/themes/{$theme->id}", $this->validData());

        $this->assertSame('theme-images/stay.jpg', $theme->fresh()->image_path);
        Storage::disk('public')->assertExists('theme-images/stay.jpg');
    }

    public function test_a_failed_save_keeps_the_old_photo_and_leaves_no_orphan_file(): void
    {
        $theme = Theme::factory()->create(['image_path' => 'theme-images/old.jpg']);
        Storage::disk('public')->put('theme-images/old.jpg', 'x');
        Theme::saving(fn () => throw new \RuntimeException('boom'));

        $this->actingAs($this->owner())->put("/owner/themes/{$theme->id}", $this->validData(['image' => UploadedFile::fake()->image('new.jpg')]))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('theme-images/old.jpg', $theme->fresh()->image_path);
        $this->assertSame(['theme-images/old.jpg'], $this->storedFiles());
    }

    public function test_a_failed_create_leaves_no_orphan_file(): void
    {
        Theme::saving(fn () => throw new \RuntimeException('boom'));

        $this->actingAs($this->owner())->post('/owner/themes', $this->validData(['image' => UploadedFile::fake()->image('new.jpg')]))->assertSessionHas('error');

        $this->assertSame([], $this->storedFiles());
        $this->assertSame(0, Theme::count());
    }

    // ---- list and tiles -------------------------------------------------

    public function test_the_list_shows_swatches_package_counts_and_a_placeholder_without_a_photo(): void
    {
        $theme = Theme::factory()->published()->create(['name' => 'Rose', 'color_swatches' => ['#AA0000']]);
        $theme->packages()->attach(PhotographyPackage::factory()->count(2)->create());
        $deleted = PhotographyPackage::factory()->create();
        $theme->packages()->attach($deleted);
        $deleted->delete();

        $this->actingAs($this->owner())->get('/owner/themes')
            ->assertOk()
            ->assertSee('Rose')
            ->assertSee('background-color: #AA0000', false)
            ->assertSee('2 packages');
    }

    public function test_search_and_status_tabs_work_and_array_values_do_not_crash(): void
    {
        Theme::factory()->published()->create(['name' => 'Alpha Wall']);
        Theme::factory()->create(['name' => 'Beta Paper']);
        $owner = $this->owner();

        $this->actingAs($owner)->get('/owner/themes?search=Alpha')->assertSee('Alpha Wall')->assertDontSee('Beta Paper');
        $this->actingAs($owner)->get('/owner/themes?status=published')->assertSee('Alpha Wall')->assertDontSee('Beta Paper');
        $this->actingAs($owner)->get('/owner/themes?status=draft')->assertSee('Beta Paper')->assertDontSee('Alpha Wall');
        $this->actingAs($owner)->get('/owner/themes?search=zzzz')->assertSee('No themes found');
        $this->actingAs($owner)->get('/owner/themes?search[]=x&status[]=y')->assertOk();
    }

    /** @return array<string, int> */
    private function stats(array $query = []): array
    {
        return $this->actingAs($this->owner())->get('/owner/themes?'.http_build_query($query))->assertOk()->viewData('stats');
    }

    public function test_an_empty_library_shows_zeros(): void
    {
        $this->assertSame(['total' => 0, 'published' => 0, 'unpublished' => 0, 'in_use' => 0], $this->stats());
    }

    public function test_the_tiles_count_published_unpublished_and_used_themes_and_ignore_deleted_rows(): void
    {
        $used = Theme::factory()->published()->create();
        Theme::factory()->published()->create();
        Theme::factory()->create();
        $gone = Theme::factory()->published()->create();
        $package = PhotographyPackage::factory()->create();
        $package->themes()->attach([$used->id, $gone->id]);
        $gone->delete();
        $deletedPackage = PhotographyPackage::factory()->create();
        $deletedPackage->themes()->attach(Theme::factory()->create());
        $deletedPackage->delete();

        $stats = $this->stats();

        $this->assertSame(['total' => 4, 'published' => 2, 'unpublished' => 2, 'in_use' => 1], $stats);
    }

    public function test_filters_do_not_change_the_tiles_and_the_page_uses_a_fixed_number_of_queries(): void
    {
        Theme::factory()->published()->count(6)->create();
        $owner = $this->owner();

        $this->assertSame($this->stats(), $this->stats(['search' => 'nothing-matches', 'status' => 'draft']));

        DB::enableQueryLog();
        $this->actingAs($owner)->get('/owner/themes')->assertOk();
        $count = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'themes'))->count();

        $this->assertLessThanOrEqual(5, $count);
    }
}
