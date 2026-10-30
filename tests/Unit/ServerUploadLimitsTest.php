<?php

namespace Tests\Unit;

use App\Support\ServerUploadLimits;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Phase 6B: reading php.ini sizes and turning them into the most the Settings page may allow. */
class ServerUploadLimitsTest extends TestCase
{
    /** @return array<string, array{string|false, ?int}> */
    public static function sizes(): array
    {
        return [
            '10M' => ['10M', 10 * 1024 * 1024],
            '40M' => ['40M', 40 * 1024 * 1024],
            'lower case and spaces' => [' 40m ', 40 * 1024 * 1024],
            '1G' => ['1G', 1024 * 1024 * 1024],
            '512K' => ['512K', 512 * 1024],
            'bytes' => ['1048576', 1048576],
            'zero means no limit' => ['0', null],
            'minus one means no limit' => ['-1', null],
            'junk' => ['ten megabytes', null],
            'unit only' => ['M', null],
            'decimal' => ['1.5M', null],
            'empty' => ['', null],
            'missing' => [false, null],
        ];
    }

    #[DataProvider('sizes')]
    public function test_php_shorthand_sizes_are_read(string|false $value, ?int $bytes): void
    {
        $this->assertSame($bytes, ServerUploadLimits::bytes($value));
    }

    public function test_the_maximums_follow_the_server_with_headroom_for_the_form(): void
    {
        $xampp = new ServerUploadLimits(['upload_max_filesize' => '10M', 'post_max_size' => '40M', 'max_file_uploads' => '20']);
        $this->assertSame(10, $xampp->maxFileMb());
        $this->assertSame(38, $xampp->maxTotalMb());
        $this->assertSame(20, $xampp->maxFiles());

        // Smaller than a megabyte still allows 1 MB; the total keeps at least 1
        $tiny = new ServerUploadLimits(['upload_max_filesize' => '512K', 'post_max_size' => '2M', 'max_file_uploads' => '3']);
        $this->assertSame(1, $tiny->maxFileMb());
        $this->assertSame(1, $tiny->maxTotalMb());
        $this->assertSame(3, $tiny->maxFiles());
    }

    public function test_unlimited_and_unreadable_values_use_the_fixed_ceiling(): void
    {
        foreach ([['0', '-1', '0'], ['junk', 'junk', 'junk'], [false, false, false], ['2G', '2G', '500']] as [$file, $post, $files]) {
            $server = new ServerUploadLimits(['upload_max_filesize' => $file, 'post_max_size' => $post, 'max_file_uploads' => $files]);

            $this->assertSame(ServerUploadLimits::CEILING_MB, $server->maxFileMb());
            $this->assertSame(ServerUploadLimits::CEILING_MB, $server->maxTotalMb());
            $this->assertSame(ServerUploadLimits::CEILING_FILES, $server->maxFiles());
        }
    }
}
