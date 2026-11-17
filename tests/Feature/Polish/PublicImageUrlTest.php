<?php

namespace Tests\Feature\Polish;

use App\Models\PackageImage;
use App\Models\PhotographyPackage;
use App\Models\Theme;
use App\Models\User;
use App\Support\PublicFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Phase 10D: public-disk images are addressed from the root, so they load under any host (a LAN address, a phone). */
class PublicImageUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_package_theme_and_avatar_addresses_are_root_relative(): void
    {
        config(['app.url' => 'http://localhost:8000', 'filesystems.disks.public.url' => 'http://localhost:8000/storage']);

        $image = PackageImage::factory()->make(['path' => 'package-images/1/cover.jpg']);
        $theme = Theme::factory()->make(['image_path' => 'themes/blue.jpg']);
        $user = User::factory()->make();
        $user->avatar_path = 'avatars/me.png';

        $this->assertSame('/storage/package-images/1/cover.jpg', $image->url());
        $this->assertSame('/storage/themes/blue.jpg', $theme->imageUrl());
        $this->assertSame('/storage/avatars/me.png', $user->avatarUrl());
    }

    public function test_a_site_in_a_sub_folder_keeps_its_folder(): void
    {
        config(['filesystems.disks.public.url' => 'https://example.test/shop/storage']);
        app('filesystem')->forgetDisk('public');

        $this->assertSame('/shop/storage/avatars/me.png', PublicFile::url('avatars/me.png'));
    }

    public function test_the_packages_page_never_prints_the_app_url_in_an_image_address(): void
    {
        $package = PhotographyPackage::factory()->published()->create();
        PackageImage::factory()->cover()->create(['photography_package_id' => $package->id, 'path' => 'package-images/9/shot.jpg']);

        // Opened under another host than APP_URL, as on a phone during a demo
        $html = $this->get('http://192.168.1.20:8000/packages')->assertOk()->getContent();

        $this->assertStringContainsString('src="/storage/package-images/9/shot.jpg"', $html);
        $this->assertStringNotContainsString('localhost:8000/storage', $html);
    }
}
