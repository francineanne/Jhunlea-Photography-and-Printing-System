<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The name a downloaded file gets: the customer's own file name, cleaned for a header (no folders, no quotes, no
 * control characters, never empty), with the extension of the STORED file, so the name cannot claim another type.
 * Used for printing files and booking reference images.
 */
class DownloadName
{
    public static function for(string $originalName, string $storedName): string
    {
        $extension = strtolower(pathinfo($storedName, PATHINFO_EXTENSION));
        $base = pathinfo(basename(str_replace('\\', '/', $originalName)), PATHINFO_FILENAME);
        $base = preg_replace('/[\x00-\x1F\x7F"\/\\\\:*?<>|;]+/u', '', (string) $base) ?? '';
        $base = trim(Str::limit(trim($base, " .\t"), 150, ''));

        if ($base === '') {
            $base = 'file';
        }

        return $extension === '' ? $base : $base.'.'.$extension;
    }
}
