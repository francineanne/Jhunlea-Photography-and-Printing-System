<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Phase 10D: the address of a file on the public disk (package photos, backdrop photos, profile pictures), always
 * root-relative ("/storage/..."). An absolute address built from APP_URL breaks as soon as the site is opened under
 * another host (127.0.0.1, a LAN address, a phone during a demo); a root-relative one works under every host.
 */
class PublicFile
{
    public static function url(string $path, string $disk = 'public'): string
    {
        $url = Storage::disk($disk)->url($path);

        // Keep only the path of the disk's address (it carries a sub-folder when the site lives in one)
        $relative = parse_url($url, PHP_URL_PATH);

        return is_string($relative) && $relative !== '' ? '/'.ltrim($relative, '/') : '/storage/'.ltrim($path, '/');
    }
}
