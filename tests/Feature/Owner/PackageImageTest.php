<?php

namespace Tests\Feature\Owner;

use App\Models\PackageImage;
use App\Models\PhotographyPackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PackageImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function owner(): User
    {
        return User::factory()->owner()->create();
    }

    /** Puts a real file on the fake disk and a matching row, like an earlier upload would have. */
    private function existingImage(PhotographyPackage $package, int $order, bool $cover = false): PackageImage
    {
        $path = "package-images/{$package->id}/existing-{$order}.jpg";
        Storage::disk('public')->put($path, 'x');

        return PackageImage::factory()->create(['photography_package_id' => $package->id, 'path' => $path, 'is_cover' => $cover, 'sort_order' => $order]);
    }

    /**
     * A real temporary file, as a browser upload would be. Laravel's UploadedFile::fake() guesses the type from the file
     * name, which would hide a renamed file; this one is sniffed from its content like a real upload.
     */
    private function realFile(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'up');
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
            'upload' => ['post', '/owner/packages/1/images'],
            'alt text' => ['put', '/owner/packages/1/images/1'],
            'cover' => ['post', '/owner/packages/1/images/1/cover'],
            'delete' => ['delete', '/owner/packages/1/images/1'],
        ];
    }

    #[DataProvider('routes')]
    public function test_a_guest_is_sent_to_the_login_page(string $method, string $url): void
    {
        $this->{$method}($url)->assertRedirect('/login');
    }

    #[DataProvider('routes')]
    public function test_a_customer_gets_403_and_nothing_is_stored(string $method, string $url): void
    {
        PhotographyPackage::factory()->create();

        $this->actingAs(User::factory()->create())->{$method}($url, ['images' => [UploadedFile::fake()->image('a.jpg')]])->assertForbidden();

        $this->assertSame([], $this->storedFiles());
    }

    // ---- upload ---------------------------------------------------------

    public function test_several_photos_are_stored_under_generated_names_and_the_first_becomes_the_cover(): void
    {
        $package = PhotographyPackage::factory()->create();

        $this->actingAs($this->owner())->post("/owner/packages/{$package->id}/images", [
            'images' => [UploadedFile::fake()->image('Holiday Photo.jpg'), UploadedFile::fake()->image('second.png'), UploadedFile::fake()->image('third.jpeg')],
        ])->assertRedirect("/owner/packages/{$package->id}/edit#gallery")->assertSessionHas('status');

        $images = $package->images()->get();
        $this->assertCount(3, $images);
        $this->assertSame([true, false, false], $images->pluck('is_cover')->all());
        $this->assertSame([1, 2, 3], $images->pluck('sort_order')->all());

        foreach ($images as $image) {
            Storage::disk('public')->assertExists($image->path);
            $this->assertStringStartsWith("package-images/{$package->id}/", $image->path);
            $this->assertStringNotContainsStringIgnoringCase('holiday', $image->path);
            $this->assertStringNotContainsStringIgnoringCase('second', $image->path);
            $this->assertMatchesRegularExpression('#^package-images/\d+/[A-Za-z0-9]{40}\.(jpg|png)$#', $image->path);
        }

        $this->assertNull($images->first()->alt_text, 'the original file name is not kept');
    }

    public function test_the_stored_extension_comes_from_the_content_and_php_names_are_refused(): void
    {
        $package = PhotographyPackage::factory()->create();
        ob_start();
        imagejpeg(imagecreatetruecolor(10, 10));
        $jpeg = (string) ob_get_clean();

        $this->actingAs($this->owner())->post("/owner/packages/{$package->id}/images", ['images' => [$this->realFile('holiday.png', $jpeg)]])->assertSessionHasNoErrors();

        $path = $package->images()->firstOrFail()->path;
        $this->assertStringEndsWith('.jpg', $path);
        $this->assertStringNotContainsString('holiday', $path);

        // Laravel also refuses any upload whose client name ends in .php, even when the content is a real photo
        $this->actingAs($this->owner())->post("/owner/packages/{$package->id}/images", ['images' => [$this->realFile('evil.php', $jpeg)]])->assertSessionHasErrors('images.0');
    }

    public function test_a_later_upload_keeps_the_cover_and_continues_the_order(): void
    {
        $package = PhotographyPackage::factory()->create();
        $first = $this->existingImage($package, 1, true);

        $this->actingAs($this->owner())->post("/owner/packages/{$package->id}/images", ['images' => [UploadedFile::fake()->image('b.jpg')]]);

        $this->assertSame(1, $package->images()->where('is_cover', true)->count());
        $this->assertTrue($first->fresh()->is_cover);
        $this->assertSame([1, 2], $package->images()->pluck('sort_order')->all());
    }

    public function test_a_webp_photo_is_accepted_when_the_server_can_make_one(): void
    {
        if (! (gd_info()['WebP Support'] ?? false)) {
            $this->markTestSkipped('This PHP build has no WebP support to create a test image.');
        }

        $package = PhotographyPackage::factory()->create();

        $this->actingAs($this->owner())->post("/owner/packages/{$package->id}/images", ['images' => [UploadedFile::fake()->image('a.webp')]])->assertSessionHasNoErrors();

        $this->assertStringEndsWith('.webp', $package->images()->firstOrFail()->path);
    }

    /** @return array<string, array{string, string}> */
    public static function badFiles(): array
    {
        return [
            'svg with a script' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            'svg renamed to jpg' => ['logo.jpg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            'php file' => ['shell.php', '<?php echo 1;'],
            'php renamed to jpg' => ['shell.jpg', '<?php system($_GET["c"]); ?>'],
            'php with a double extension' => ['shell.php.jpg', '<?php system($_GET["c"]); ?>'],
            'pdf' => ['paper.pdf', "%PDF-1.4\n1 0 obj\n<< >>\nendobj\n%%EOF"],
            'plain text renamed to png' => ['notes.png', 'just some text'],
        ];
    }

    #[DataProvider('badFiles')]
    public function test_files_that_are_not_real_jpg_png_or_webp_photos_are_rejected(string $name, string $content): void
    {
        $package = PhotographyPackage::factory()->create();

        $this->actingAs($this->owner())->post("/owner/packages/{$package->id}/images", ['images' => [$this->realFile($name, $content)]])
            ->assertSessionHasErrors('images.0');

        $this->assertSame([], $this->storedFiles());
        $this->assertSame(0, PackageImage::count());
    }

    public function test_a_gif_is_rejected(): void
    {
        $package = PhotographyPackage::factory()->create();

        $this->actingAs($this->owner())->post("/owner/packages/{$package->id}/images", ['images' => [UploadedFile::fake()->image('anim.gif')]])->assertSessionHasErrors('images.0');

        $this->assertSame([], $this->storedFiles());
    }

    public function test_a_photo_over_the_size_limit_is_rejected(): void
    {
        $package = PhotographyPackage::factory()->create();

        $this->actingAs($this->owner())->post("/owner/packages/{$package->id}/images", ['images' => [UploadedFile::fake()->image('big.jpg')->size(PackageImage::MAX_SIZE_KB + 1)]])
            ->assertSessionHasErrors('images.0');

        $this->assertSame([], $this->storedFiles());
    }

    public function test_a_failed_upload_or_description_returns_to_the_gallery(): void
    {
        $package = PhotographyPackage::factory()->create();
        $image = $this->existingImage($package, 1, true);
        $owner = $this->owner();

        $this->actingAs($owner)->post("/owner/packages/{$package->id}/images", [])->assertRedirect("/owner/packages/{$package->id}/edit#gallery");
        $this->actingAs($owner)->put("/owner/packages/{$package->id}/images/{$image->id}", ['alt_text' => str_repeat('a', 151)])->assertRedirect("/owner/packages/{$package->id}/edit#gallery");
    }

    public function test_a_photo_with_a_huge_pixel_size_is_rejected_even_if_the_file_is_small(): void
    {
        $package = PhotographyPackage::factory()->create();

        $this->actingAs($this->owner())->post("/owner/packages/{$package->id}/images", ['images' => [UploadedFile::fake()->image('wide.png', PackageImage::MAX_PIXELS + 1, 10)]])
            ->assertSessionHasErrors('images.0');

        $this->assertSame([], $this->storedFiles());
    }

    public function test_setting_a_cover_on_a_photo_that_was_just_deleted_changes_nothing(): void
    {
        $package = PhotographyPackage::factory()->create();
        $cover = $this->existingImage($package, 1, true);
        $gone = $this->existingImage($package, 2);
        $stale = PackageImage::find($gone->id);
        $gone->delete();
        $route = "/owner/packages/{$package->id}/images/{$stale->id}/cover";

        $this->actingAs($this->owner())->post($route)->assertNotFound();

        $this->assertTrue($cover->fresh()->is_cover, 'the existing cover is not reset');
    }

    public function test_deleting_a_photo_that_is_already_gone_does_nothing_and_keeps_the_cover(): void
    {
        $package = PhotographyPackage::factory()->create();
        $cover = $this->existingImage($package, 1, true);

        $this->actingAs($this->owner())->delete("/owner/packages/{$package->id}/images/99999")->assertNotFound();

        $this->assertTrue($cover->fresh()->is_cover);
        Storage::disk('public')->assertExists($cover->path);
    }

    public function test_one_bad_file_stops_the_whole_batch(): void
    {
        $package = PhotographyPackage::factory()->create();

        $this->actingAs($this->owner())->post("/owner/packages/{$package->id}/images", [
            'images' => [UploadedFile::fake()->image('ok.jpg'), $this->realFile('bad.jpg', '<?php echo 1;')],
        ])->assertSessionHasErrors('images.1');

        $this->assertSame([], $this->storedFiles());
        $this->assertSame(0, PackageImage::count());
    }

    public function test_a_file_bigger_than_the_php_limit_gets_a_plain_message(): void
    {
        $package = PhotographyPackage::factory()->create();
        $path = tempnam(sys_get_temp_dir(), 'big');
        $tooBig = new UploadedFile($path, 'huge.jpg', 'image/jpeg', UPLOAD_ERR_INI_SIZE, true);

        $this->actingAs($this->owner())->post("/owner/packages/{$package->id}/images", ['images' => [$tooBig]])
            ->assertSessionHasErrors(['images.0' => 'huge.jpg is bigger than this server accepts ('.ini_get('upload_max_filesize').' per file).']);

        $this->assertSame([], $this->storedFiles());
        @unlink($path);
    }

    public function test_an_empty_upload_and_a_non_file_value_are_rejected(): void
    {
        $package = PhotographyPackage::factory()->create();
        $owner = $this->owner();

        $this->actingAs($owner)->post("/owner/packages/{$package->id}/images", [])->assertSessionHasErrors('images');
        $this->actingAs($owner)->post("/owner/packages/{$package->id}/images", ['images' => ['not a file']])->assertSessionHasErrors('images.0');
        $this->actingAs($owner)->post("/owner/packages/{$package->id}/images", ['images' => 'abc'])->assertSessionHasErrors('images');
    }

    public function test_more_than_ten_photos_at_once_are_rejected(): void
    {
        $package = PhotographyPackage::factory()->create();
        $files = array_map(fn ($i) => UploadedFile::fake()->image("p{$i}.jpg"), range(1, PackageImage::MAX_PER_REQUEST + 1));

        $this->actingAs($this->owner())->post("/owner/packages/{$package->id}/images", ['images' => $files])->assertSessionHasErrors('images');

        $this->assertSame([], $this->storedFiles());
    }

    public function test_a_package_cannot_go_over_twelve_photos(): void
    {
        $package = PhotographyPackage::factory()->create();
        foreach (range(1, 10) as $order) {
            $this->existingImage($package, $order, $order === 1);
        }
        $before = $this->storedFiles();

        $this->actingAs($this->owner())->post("/owner/packages/{$package->id}/images", ['images' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg'), UploadedFile::fake()->image('c.jpg')]])
            ->assertSessionHasErrors('images');

        $this->assertSame($before, $this->storedFiles());
        $this->assertSame(10, $package->images()->count());

        $this->actingAs($this->owner())->post("/owner/packages/{$package->id}/images", ['images' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')]])->assertSessionHasNoErrors();
        $this->assertSame(12, $package->images()->count());
    }

    public function test_a_failed_save_leaves_no_orphan_file_and_shows_a_message(): void
    {
        $package = PhotographyPackage::factory()->create();
        PackageImage::saving(fn () => throw new \RuntimeException('boom'));

        $this->actingAs($this->owner())->post("/owner/packages/{$package->id}/images", ['images' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')]])
            ->assertRedirect("/owner/packages/{$package->id}/edit#gallery")
            ->assertSessionHas('error');

        $this->assertSame([], $this->storedFiles());
        $this->assertSame(0, PackageImage::count());
    }

    // ---- cover ----------------------------------------------------------

    public function test_setting_a_cover_keeps_exactly_one(): void
    {
        $package = PhotographyPackage::factory()->create();
        $first = $this->existingImage($package, 1, true);
        $second = $this->existingImage($package, 2);
        $owner = $this->owner();

        $this->actingAs($owner)->post("/owner/packages/{$package->id}/images/{$second->id}/cover")->assertRedirect("/owner/packages/{$package->id}/edit#gallery");
        $this->actingAs($owner)->post("/owner/packages/{$package->id}/images/{$second->id}/cover");

        $this->assertSame(1, $package->images()->where('is_cover', true)->count());
        $this->assertTrue($second->fresh()->is_cover);
        $this->assertFalse($first->fresh()->is_cover);
    }

    public function test_another_packages_cover_is_not_touched(): void
    {
        $mine = PhotographyPackage::factory()->create();
        $other = PhotographyPackage::factory()->create();
        $otherCover = $this->existingImage($other, 1, true);
        $this->existingImage($mine, 1, true);
        $second = $this->existingImage($mine, 2);

        $this->actingAs($this->owner())->post("/owner/packages/{$mine->id}/images/{$second->id}/cover");

        $this->assertTrue($otherCover->fresh()->is_cover);
    }

    // ---- delete ---------------------------------------------------------

    public function test_deleting_a_photo_removes_the_row_and_the_file(): void
    {
        $package = PhotographyPackage::factory()->create();
        $this->existingImage($package, 1, true);
        $second = $this->existingImage($package, 2);

        $this->actingAs($this->owner())->delete("/owner/packages/{$package->id}/images/{$second->id}")->assertRedirect("/owner/packages/{$package->id}/edit#gallery");

        Storage::disk('public')->assertMissing($second->path);
        $this->assertNull(PackageImage::find($second->id));
        $this->assertSame(1, $package->images()->where('is_cover', true)->count());
    }

    public function test_deleting_the_cover_promotes_the_next_photo(): void
    {
        $package = PhotographyPackage::factory()->create();
        $cover = $this->existingImage($package, 1, true);
        $next = $this->existingImage($package, 2);
        $last = $this->existingImage($package, 3);

        $this->actingAs($this->owner())->delete("/owner/packages/{$package->id}/images/{$cover->id}");

        Storage::disk('public')->assertMissing($cover->path);
        $this->assertTrue($next->fresh()->is_cover);
        $this->assertFalse($last->fresh()->is_cover);
        $this->assertSame(1, $package->images()->where('is_cover', true)->count());
    }

    public function test_deleting_the_only_photo_leaves_a_package_with_no_cover(): void
    {
        $package = PhotographyPackage::factory()->create();
        $only = $this->existingImage($package, 1, true);

        $this->actingAs($this->owner())->delete("/owner/packages/{$package->id}/images/{$only->id}");

        $this->assertSame(0, $package->images()->count());
        $this->assertSame([], $this->storedFiles());
    }

    public function test_the_next_upload_after_deleting_everything_becomes_the_cover(): void
    {
        $package = PhotographyPackage::factory()->create();
        $only = $this->existingImage($package, 1, true);
        $owner = $this->owner();

        $this->actingAs($owner)->delete("/owner/packages/{$package->id}/images/{$only->id}");
        $this->actingAs($owner)->post("/owner/packages/{$package->id}/images", ['images' => [UploadedFile::fake()->image('new.jpg')]]);

        $this->assertTrue($package->images()->firstOrFail()->is_cover);
    }

    public function test_soft_deleting_a_package_keeps_its_photos_on_disk(): void
    {
        $package = PhotographyPackage::factory()->create();
        $image = $this->existingImage($package, 1, true);

        $this->actingAs($this->owner())->delete("/owner/packages/{$package->id}");

        Storage::disk('public')->assertExists($image->path);
        $this->assertSame(1, PackageImage::count());
    }

    // ---- id tampering ---------------------------------------------------

    public function test_a_photo_cannot_be_reached_through_another_packages_url(): void
    {
        $mine = PhotographyPackage::factory()->create();
        $theirs = PhotographyPackage::factory()->create();
        $image = $this->existingImage($theirs, 1, true);
        $owner = $this->owner();

        $this->actingAs($owner)->put("/owner/packages/{$mine->id}/images/{$image->id}", ['alt_text' => 'Hacked'])->assertNotFound();
        $this->actingAs($owner)->post("/owner/packages/{$mine->id}/images/{$image->id}/cover")->assertNotFound();
        $this->actingAs($owner)->delete("/owner/packages/{$mine->id}/images/{$image->id}")->assertNotFound();

        Storage::disk('public')->assertExists($image->path);
        $this->assertNull($image->fresh()->alt_text);
    }

    public function test_photos_of_a_deleted_package_cannot_be_changed(): void
    {
        $package = PhotographyPackage::factory()->create();
        $image = $this->existingImage($package, 1, true);
        $package->delete();

        $this->actingAs($this->owner())->delete("/owner/packages/{$package->id}/images/{$image->id}")->assertNotFound();

        Storage::disk('public')->assertExists($image->path);
    }

    public function test_the_path_and_flags_cannot_be_forced_through_the_description_form(): void
    {
        $package = PhotographyPackage::factory()->create();
        $image = $this->existingImage($package, 1, true);
        $other = $this->existingImage($package, 2);

        $this->actingAs($this->owner())->put("/owner/packages/{$package->id}/images/{$other->id}", ['alt_text' => 'Fine', 'path' => '../../evil.php', 'is_cover' => 1, 'photography_package_id' => 999]);

        $other->refresh();
        $this->assertSame('Fine', $other->alt_text);
        $this->assertSame("package-images/{$package->id}/existing-2.jpg", $other->path);
        $this->assertFalse($other->is_cover);
        $this->assertSame($package->id, $other->photography_package_id);
        $this->assertTrue($image->fresh()->is_cover);
    }

    // ---- description ----------------------------------------------------

    public function test_the_owner_can_edit_and_clear_a_photos_description(): void
    {
        $package = PhotographyPackage::factory()->create();
        $image = $this->existingImage($package, 1, true);
        $owner = $this->owner();

        $this->actingAs($owner)->put("/owner/packages/{$package->id}/images/{$image->id}", ['alt_text' => 'A family by a window']);
        $this->assertSame('A family by a window', $image->fresh()->alt_text);

        $this->actingAs($owner)->put("/owner/packages/{$package->id}/images/{$image->id}", ['alt_text' => '']);
        $this->assertNull($image->fresh()->alt_text);
    }

    public function test_a_too_long_description_is_rejected_in_that_photos_own_error_bag(): void
    {
        $package = PhotographyPackage::factory()->create();
        $image = $this->existingImage($package, 1, true);

        $this->actingAs($this->owner())->put("/owner/packages/{$package->id}/images/{$image->id}", ['alt_text' => str_repeat('a', 151)])
            ->assertSessionHasErrorsIn("image_{$image->id}", 'alt_text');
    }

    // ---- pages ----------------------------------------------------------

    public function test_the_edit_page_shows_the_gallery_and_the_list_shows_the_cover(): void
    {
        $package = PhotographyPackage::factory()->create(['name' => 'Gallery Package']);
        $cover = $this->existingImage($package, 1, true);
        $owner = $this->owner();

        $this->actingAs($owner)->get("/owner/packages/{$package->id}/edit")
            ->assertOk()
            ->assertSee('1 of 12 photos')
            ->assertSee('Cover');

        $this->actingAs($owner)->get("/owner/packages/{$package->id}/edit")->assertSee($cover->url(), false);
        $this->actingAs($owner)->get('/owner/packages')->assertSee($cover->url(), false);
    }

    public function test_the_edit_page_with_a_full_gallery_hides_the_upload_form(): void
    {
        $package = PhotographyPackage::factory()->create();
        foreach (range(1, 12) as $order) {
            $this->existingImage($package, $order, $order === 1);
        }

        $this->actingAs($this->owner())->get("/owner/packages/{$package->id}/edit")
            ->assertOk()
            ->assertSee('the most photos it can hold')
            ->assertDontSee('Upload photos');
    }

    public function test_the_too_large_error_page_is_friendly(): void
    {
        $html = view('errors.413')->render();

        $this->assertStringContainsString('That upload was too large', $html);
        $this->assertStringContainsString(ini_get('post_max_size'), $html);
    }
}
