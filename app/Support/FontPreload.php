<?php

namespace App\Support;

/**
 * Phase 10D: the font files worth preloading (the two text fonts in their Latin range and the icon font), read from the
 * Vite build manifest, so headings and icons are there with the first paint and nothing shifts when they arrive.
 * In development (the Vite dev server) and in the tests there is no build to read: nothing is preloaded.
 */
class FontPreload
{
    private const WANTED = ['plus-jakarta-sans-latin-wght-normal', 'playfair-display-latin-wght-normal', 'material-symbols-outlined'];

    /** @var list<string>|null */
    private static ?array $files = null;

    /** @return list<string> public addresses of the font files to preload */
    public static function files(): array
    {
        if (self::$files !== null) {
            return self::$files;
        }

        $manifest = public_path('build/manifest.json');

        if (app()->runningUnitTests() || is_file(public_path('hot')) || ! is_file($manifest)) {
            return self::$files = [];
        }

        $entries = json_decode((string) file_get_contents($manifest), true);
        $assets = is_array($entries) ? ($entries['resources/css/app.css']['assets'] ?? []) : [];
        $found = [];

        foreach (is_array($assets) ? $assets : [] as $asset) {
            foreach (self::WANTED as $name) {
                if (is_string($asset) && str_ends_with($asset, '.woff2') && str_contains($asset, $name.'-')) {
                    $found[] = '/build/'.ltrim($asset, '/');
                }
            }
        }

        return self::$files = $found;
    }
}
