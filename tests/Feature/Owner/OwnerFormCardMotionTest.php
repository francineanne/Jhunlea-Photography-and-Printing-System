<?php

namespace Tests\Feature\Owner;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Tailwind 4 moves an element with the `translate` property, not `transform`, so a lift that lists only `transform`
 * snaps instead of easing. The preview cards on the owner forms must list `translate`.
 */
class OwnerFormCardMotionTest extends TestCase
{
    public static function forms(): array
    {
        return [
            'packages' => ['owner/packages/_form.blade.php'],
            'services' => ['owner/services/_form.blade.php'],
            'themes' => ['owner/themes/_form.blade.php'],
        ];
    }

    #[DataProvider('forms')]
    public function test_the_preview_card_lift_eases_its_translate(string $view): void
    {
        $html = file_get_contents(resource_path('views/'.$view));

        $this->assertStringContainsString('transition-[transform,translate,box-shadow]', $html);
        $this->assertStringNotContainsString('transition-[transform,box-shadow]', $html);
    }
}
