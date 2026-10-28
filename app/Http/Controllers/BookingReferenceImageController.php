<?php

namespace App\Http\Controllers;

use App\Models\BookingReferenceImage;
use App\Support\DownloadName;
use finfo;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A booking's reference image, for the owner or the customer whose booking it is (BookingReferenceImagePolicy), and
 * nobody else. Shown inside the page only when the file's own bytes are a JPEG, PNG or WebP image (never trusting the
 * stored mime column), with a fixed type from that list; anything else is a download with a neutral type. Always
 * nosniff, a sandboxing CSP and no caching, and the path, stored name and disk never reach the visitor.
 */
class BookingReferenceImageController extends Controller
{
    /** Types that may be shown inline, as found in the file's content. */
    private const INLINE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    private const HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'Content-Security-Policy' => "default-src 'none'; sandbox",
        'Cache-Control' => 'private, no-store',
    ];

    public function show(BookingReferenceImage $image): StreamedResponse
    {
        return $this->send($image, inline: true);
    }

    public function download(BookingReferenceImage $image): StreamedResponse
    {
        return $this->send($image, inline: false);
    }

    private function send(BookingReferenceImage $image, bool $inline): StreamedResponse
    {
        Gate::authorize('view', $image);

        $disk = Storage::disk(config('booking.disk'));

        // The record exists but the bytes do not: a plain 404, without saying where it looked
        abort_unless($disk->exists($image->path), 404);

        $name = DownloadName::for($image->original_name, $image->stored_name);
        $type = $inline ? $this->realImageType($disk->readStream($image->path)) : null;

        if ($type !== null) {
            return $disk->response($image->path, $name, ['Content-Type' => $type] + self::HEADERS, 'inline');
        }

        return $disk->download($image->path, $name, ['Content-Type' => 'application/octet-stream'] + self::HEADERS);
    }

    /** @param  resource|null  $stream */
    private function realImageType($stream): ?string
    {
        if (! is_resource($stream)) {
            return null;
        }

        $head = (string) fread($stream, 4096);
        fclose($stream);

        $type = (new finfo(FILEINFO_MIME_TYPE))->buffer($head);

        return in_array($type, self::INLINE_TYPES, true) ? $type : null;
    }
}
