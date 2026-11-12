<?php

namespace App\Support\Preflight;

use Illuminate\Support\Facades\DB;

/**
 * What jhunlea:preflight reads from the machine (Phase 9A): PHP settings, extensions and folders. One small class so a
 * test can bind a fake one; everything here only reads.
 */
class PreflightProbe
{
    public function ini(string $key): string|false
    {
        return ini_get($key);
    }

    public function extensionLoaded(string $name): bool
    {
        return extension_loaded($name);
    }

    public function writable(string $path): bool
    {
        return is_dir($path) && is_writable($path);
    }

    /**
     * The public/storage link: a symlink, a Windows junction (storage:link makes one on XAMPP; PHP sees it only through
     * readlink) or a plain folder, which some hosts use instead.
     */
    public function storageLinked(): bool
    {
        $link = public_path('storage');

        return is_link($link) || is_dir($link) || (file_exists($link) && @readlink($link) !== false);
    }

    public function fileExists(string $path): bool
    {
        return $path !== '' && is_file($path);
    }

    public function databaseDriver(): string
    {
        return (string) DB::connection()->getDriverName();
    }

    /** Opens the connection only; throws when it cannot. */
    public function connect(): void
    {
        DB::connection()->getPdo();
    }
}
