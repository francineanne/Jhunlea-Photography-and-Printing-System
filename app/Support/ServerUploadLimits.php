<?php

namespace App\Support;

/**
 * What this PHP server can accept in one request, read from php.ini at runtime (upload_max_filesize, post_max_size,
 * max_file_uploads). ShopSettings never lets an upload limit go above these: PHP drops a bigger file or a bigger form
 * before Laravel can explain why.
 *
 * - One file may not exceed upload_max_filesize.
 * - All files of one request together may not exceed post_max_size minus HEADROOM_MB, which is kept for the rest of
 *   the form.
 * - The number of files may not exceed max_file_uploads.
 * - An unlimited (0 or -1) or unreadable value means CEILING_MB (or CEILING_FILES): a fixed, safe ceiling.
 */
final class ServerUploadLimits
{
    public const CEILING_MB = 100;

    public const CEILING_FILES = 50;

    public const HEADROOM_MB = 2;

    /** @param  array<string, string|false>|null  $ini  the php.ini values to use instead of the real ones (tests) */
    public function __construct(private readonly ?array $ini = null) {}

    /** The largest single file in whole MB this server accepts. */
    public function maxFileMb(): int
    {
        return self::wholeMb(self::bytes($this->value('upload_max_filesize')));
    }

    /** The largest total of all files of one request in whole MB, leaving HEADROOM_MB for the rest of the form. */
    public function maxTotalMb(): int
    {
        $post = self::bytes($this->value('post_max_size'));

        return $post === null ? self::CEILING_MB : max(1, min(self::CEILING_MB, intdiv($post, 1024 * 1024) - self::HEADROOM_MB));
    }

    /** The most files one request may carry. */
    public function maxFiles(): int
    {
        $files = $this->value('max_file_uploads');

        return is_numeric($files) && (int) $files > 0 ? min(self::CEILING_FILES, (int) $files) : self::CEILING_FILES;
    }

    /** The raw php.ini values, for showing to the owner. @return array{file: string, post: string, files: string} */
    public function raw(): array
    {
        return [
            'file' => (string) $this->value('upload_max_filesize'),
            'post' => (string) $this->value('post_max_size'),
            'files' => (string) $this->value('max_file_uploads'),
        ];
    }

    /**
     * A php.ini size ("10M", "40M", "1G", "512K", "1048576") in bytes. Null when it means "no limit" (0 or a negative
     * value) or cannot be read, so the caller uses the fixed ceiling.
     */
    public static function bytes(string|false|null $value): ?int
    {
        $value = strtoupper(trim((string) $value));

        if (! preg_match('/^(-?\d+)\s*([KMG]?)$/', $value, $match)) {
            return null;
        }

        $number = (int) $match[1];

        if ($number <= 0) {
            return null;
        }

        return $number * match ($match[2]) {
            'K' => 1024,
            'M' => 1024 * 1024,
            'G' => 1024 * 1024 * 1024,
            default => 1,
        };
    }

    private static function wholeMb(?int $bytes): int
    {
        return $bytes === null ? self::CEILING_MB : max(1, min(self::CEILING_MB, intdiv($bytes, 1024 * 1024)));
    }

    private function value(string $key): string|false
    {
        return $this->ini !== null ? ($this->ini[$key] ?? false) : ini_get($key);
    }
}
