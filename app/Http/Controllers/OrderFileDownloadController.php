<?php

namespace App\Http\Controllers;

use App\Models\OrderFile;
use App\Support\DownloadName;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Hands a customer's file to the shop owner or to the customer who sent it, and to nobody else (OrderFilePolicy).
 * The file is always a download: a fixed, neutral type and nosniff, so a browser never opens or runs it, and the
 * path, stored name and disk never reach the visitor.
 */
class OrderFileDownloadController extends Controller
{
    public function __invoke(OrderFile $file): StreamedResponse
    {
        Gate::authorize('view', $file);

        $disk = Storage::disk(config('printing.disk'));

        // The record exists but the bytes do not: a plain 404, without saying where it looked
        abort_unless($disk->exists($file->path), 404);

        return $disk->download($file->path, self::safeName($file), [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    /**
     * The customer's own file name, cleaned for a header: no folders, no quotes, no control characters, never empty,
     * and with the extension of the stored file so the name cannot claim a different type.
     */
    public static function safeName(OrderFile $file): string
    {
        return DownloadName::for($file->original_name, $file->stored_name);
    }
}
