<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * The one place that checks an uploaded file against a list of accepted types, shared by printing files
 * (OrderFileService) and booking reference images (BookingImageService). Each caller passes its own types and
 * limits from its own config file, and its own wording for the two messages that name the limits.
 *
 * A file passes only when its extension AND the MIME type found by reading its own bytes (never the one the browser
 * sent) belong to the same accepted type.
 */
final class UploadCheck
{
    /**
     * @param  array<string, array{extensions: list<string>, mimes: list<string>}>  $types  the accepted types, keyed by type
     *
     * @throws ValidationException
     */
    public static function file(UploadedFile $file, array $types, int $maxBytes, string $tooLarge, string $wrongType, string $field = 'file'): void
    {
        if (! $file->isValid()) {
            self::fail($field, 'The file did not upload completely. Please try again.');
        }

        $size = $file->getSize();

        if (! $size) {
            self::fail($field, 'The file is empty.');
        }

        if ($size > $maxBytes) {
            self::fail($field, $tooLarge);
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $type = self::typeFor($types, $extension);

        if ($type === null) {
            self::fail($field, $wrongType);
        }

        // The type the server finds by reading the file itself, not the one the browser claims
        if (! in_array($file->getMimeType(), $types[$type]['mimes'], true)) {
            self::fail($field, 'The contents of this file do not match a .'.$extension.' file.');
        }
    }

    /**
     * The limit for all files of one record together, counting the files it already holds.
     *
     * @param  array<int, UploadedFile>  $files
     *
     * @throws ValidationException
     */
    public static function total(array $files, int $maxMegabytes, string $holder, int $alreadyStoredBytes = 0, string $field = 'files'): void
    {
        $total = $alreadyStoredBytes + array_sum(array_map(fn (UploadedFile $file) => (int) $file->getSize(), $files));

        if ($total > $maxMegabytes * 1024 * 1024) {
            // Rounded up, so a total just over the limit never reads as the limit itself
            $size = number_format(ceil($total / 1024 / 1024 * 10) / 10, 1);

            self::fail($field, "Your files together are {$size} MB. The most {$holder} can hold is {$maxMegabytes} MB. Please remove or compress some files.");
        }
    }

    /** The customer's file name, only for showing to people: no folders, no control characters, a sensible length. */
    public static function displayName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name);

        return mb_substr($name === '' ? 'file' : $name, 0, 255);
    }

    /** @param  array<string, array{extensions: list<string>, mimes: list<string>}>  $types */
    private static function typeFor(array $types, string $extension): ?string
    {
        foreach ($types as $type => $rule) {
            if (in_array($extension, $rule['extensions'], true)) {
                return $type;
            }
        }

        return null;
    }

    private static function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
