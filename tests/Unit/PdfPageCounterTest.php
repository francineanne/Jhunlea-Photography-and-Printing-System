<?php

namespace Tests\Unit;

use App\Support\PdfPageCounter;
use PHPUnit\Framework\TestCase;
use Tests\Support\MakesPdfs;

/** Phase 10R: the page count of a PDF without a library, or null when it cannot tell. */
class PdfPageCounterTest extends TestCase
{
    use MakesPdfs;

    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function file(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    public function test_a_normal_pdf_is_counted_from_its_page_tree(): void
    {
        $counter = new PdfPageCounter;

        $this->assertSame(1, $counter->count($this->file($this->pdf(1))));
        $this->assertSame(3, $counter->count($this->file($this->pdf(3))));
        $this->assertSame(12, $counter->count($this->file($this->pdf(12))));
    }

    public function test_a_page_tree_inside_an_object_stream_is_counted(): void
    {
        $this->assertSame(4, (new PdfPageCounter)->count($this->file($this->pdf(4, compressed: true))));
    }

    public function test_the_root_page_tree_wins_over_stray_page_words_and_updates_replace_it(): void
    {
        // An incremental update rewrites the page tree with one page fewer: the last definition counts
        $updated = $this->pdf(3)."2 0 obj\n<< /Type /Pages /Kids [11 0 R 12 0 R] /Count 2 >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

        $this->assertSame(2, (new PdfPageCounter)->count($this->file($updated)));
    }

    public function test_without_a_page_tree_the_leaves_are_counted(): void
    {
        $leavesOnly = "%PDF-1.4\n4 0 obj\n<< /Type /Page >>\nendobj\n5 0 obj\n<< /Type /Page >>\nendobj\n6 0 obj\n<< /Type /Pages >>\nendobj\n%%EOF\n";

        $this->assertSame(2, (new PdfPageCounter)->count($this->file($leavesOnly)));
    }

    public function test_a_broken_or_encrypted_pdf_is_unknown(): void
    {
        $counter = new PdfPageCounter;

        $this->assertNull($counter->count($this->file("%PDF-1.4\nnothing to read here\n%%EOF\n")));
        $this->assertNull($counter->count($this->file("%PDF-1.7\n1 0 obj\n<< /Type /Pages /Count 0 >>\nendobj\n")));
        $this->assertNull($counter->count($this->file(substr($this->pdf(3, compressed: true), 0, 120))));
        $this->assertNull($counter->count($this->file($this->pdf(3, encrypted: true))));
        $this->assertNull($counter->count($this->file('')));
    }

    public function test_a_file_that_is_not_a_pdf_is_unknown(): void
    {
        $counter = new PdfPageCounter;

        $this->assertNull($counter->count($this->file("PK\x03\x04 a zip file with /Type /Page inside")));
        $this->assertNull($counter->count($this->file("\xFF\xD8\xFF\xE0 jpeg bytes")));
        $this->assertNull($counter->count(sys_get_temp_dir().'/does-not-exist-'.uniqid().'.pdf'));
    }
}
