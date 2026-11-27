<?php

namespace Tests\Support;

/**
 * Phase 10R: small PDFs built in the test itself (no binary fixtures). A plain one has its catalog, page tree and pages
 * written out; a compressed one keeps them in a FlateDecode object stream with an xref stream, as many PDF writers do.
 * Each page has a content stream whose body says "/Type /Page" on purpose: stream bodies must never be counted.
 */
trait MakesPdfs
{
    protected function pdf(int $pages, bool $compressed = false, bool $encrypted = false): string
    {
        $kids = implode(' ', array_map(fn (int $i) => (10 + $i).' 0 R', range(1, $pages)));
        $catalog = '<< /Type /Catalog /Pages 2 0 R >>';
        $tree = "<< /Type /Pages /Kids [{$kids}] /Count {$pages} >>";
        $leaves = array_map(fn (int $i) => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 3 0 R >>', range(1, $pages));
        $content = "3 0 obj\n<< /Length 30 >>\nstream\nBT (/Type /Page /Type /Page) ET\nendstream\nendobj\n";
        $encrypt = $encrypted ? ' /Encrypt 9 0 R' : '';

        if (! $compressed) {
            $body = "1 0 obj\n{$catalog}\nendobj\n2 0 obj\n{$tree}\nendobj\n".$content;

            foreach ($leaves as $i => $leaf) {
                $body .= (11 + $i)." 0 obj\n{$leaf}\nendobj\n";
            }

            return "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n{$body}trailer\n<< /Size ".(11 + $pages)." /Root 1 0 R{$encrypt} >>\nstartxref\n0\n%%EOF\n";
        }

        // Objects 1, 2 and the leaves inside one object stream; the trailer is an xref stream dictionary
        $objects = array_merge([$catalog, $tree], $leaves);
        $numbers = array_merge([1, 2], array_map(fn (int $i) => 10 + $i, range(1, $pages)));
        $header = '';
        $data = '';

        foreach ($objects as $i => $object) {
            $header .= $numbers[$i].' '.strlen($data).' ';
            $data .= $object."\n";
        }

        $packed = gzcompress($header.$data);
        $objStm = "5 0 obj\n<< /Type /ObjStm /N ".count($objects).' /First '.strlen($header).' /Filter /FlateDecode /Length '.strlen($packed)." >>\nstream\n{$packed}\nendstream\nendobj\n";
        $xref = gzcompress("\x01\x00\x00");
        $xrefStm = "6 0 obj\n<< /Type /XRef /Size 20 /Root 1 0 R{$encrypt} /Filter /FlateDecode /Length ".strlen($xref)." >>\nstream\n{$xref}\nendstream\nendobj\n";

        return "%PDF-1.5\n%\xE2\xE3\xCF\xD3\n".$content.$objStm.$xrefStm."startxref\n0\n%%EOF\n";
    }
}
