<?php

namespace App\Support;

/**
 * Phase 10R: the number of pages of a PDF, read from its structure without a library. Returns null whenever it cannot
 * tell for sure (not a PDF, encrypted, broken, too big, or a structure it cannot read); null means "unknown" and the
 * customer types the number of pages instead. Never throws.
 *
 * The order of trust:
 *   1. the /Count of the /Pages object the document's /Root points at (the last definition wins, as in an update);
 *   2. the largest /Count of any /Type /Pages dictionary (the root of the page tree holds every page);
 *   3. the number of /Type /Page dictionaries (the leaves).
 * Stream bodies are skipped, except object streams (/Type /ObjStm, FlateDecode), which are inflated and read too,
 * because many PDF writers keep the page tree inside them. resources/js/app.js (countPdfPages) does the same in the
 * browser, only to show the count early; this class decides.
 */
class PdfPageCounter
{
    /** Files above this size are not read (memory); the customer types the pages instead. */
    public const MAX_BYTES = 64 * 1024 * 1024;

    /** The largest page count believed; anything above is treated as unknown. */
    public const MAX_PAGES = 100000;

    /** At most this many object streams are inflated, each up to MAX_INFLATED bytes. */
    private const MAX_OBJECT_STREAMS = 500;

    private const MAX_INFLATED = 8 * 1024 * 1024;

    public function count(string $path): ?int
    {
        try {
            if (! is_file($path) || ! is_readable($path)) {
                return null;
            }

            $size = filesize($path);

            if ($size === false || $size < 8 || $size > self::MAX_BYTES) {
                return null;
            }

            $raw = file_get_contents($path);

            return is_string($raw) ? $this->countBytes($raw) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function countBytes(string $raw): ?int
    {
        // A PDF starts with %PDF- (a few junk bytes before it are allowed by readers)
        $head = strpos(substr($raw, 0, 1024), '%PDF-');

        if ($head === false) {
            return null;
        }

        $skeleton = $this->skeleton($raw);
        unset($raw);

        // Encrypted files: strings and streams are not readable, so the count is not trusted
        if (preg_match('/\/Encrypt\b/', $skeleton) === 1) {
            return null;
        }

        foreach ([$this->fromRoot($skeleton), $this->largestPagesCount($skeleton), $this->leafCount($skeleton)] as $count) {
            if ($count !== null && $count >= 1 && $count <= self::MAX_PAGES) {
                return $count;
            }
        }

        return null;
    }

    /** The file without its stream bodies, with the inflated object streams added at the end. */
    private function skeleton(string $raw): string
    {
        $parts = [];
        $inflated = [];
        $offset = 0;
        $length = strlen($raw);

        while ($offset < $length && preg_match('/(?<!end)stream\r?\n/', $raw, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = $match[0][1];
            $bodyStart = $start + strlen($match[0][0]);
            $end = strpos($raw, 'endstream', $bodyStart);

            if ($end === false) {
                break;
            }

            $before = substr($raw, $offset, $start - $offset);
            $parts[] = $before;

            // The stream's own dictionary is the text since the last "obj" keyword
            $objAt = strrpos($before, 'obj');
            $dictionary = $objAt === false ? $before : substr($before, $objAt);

            if (count($inflated) < self::MAX_OBJECT_STREAMS && preg_match('/\/Type\s*\/ObjStm\b/', $dictionary) === 1 && str_contains($dictionary, '/FlateDecode')) {
                $text = $this->inflate(rtrim(substr($raw, $bodyStart, $end - $bodyStart), "\r\n"));

                if ($text !== null) {
                    $inflated[] = $text;
                }
            }

            $offset = $end + strlen('endstream');
        }

        $parts[] = substr($raw, $offset);

        return implode("\n", $parts)."\n".implode("\n", $inflated);
    }

    private function inflate(string $data): ?string
    {
        $text = @gzuncompress($data, self::MAX_INFLATED);

        if ($text === false) {
            $text = @gzinflate(substr($data, 2), self::MAX_INFLATED);
        }

        return is_string($text) ? $text : null;
    }

    /** 1. /Root -> catalog -> /Pages -> /Count, when those objects are written out in the file itself. */
    private function fromRoot(string $text): ?int
    {
        if (preg_match_all('/\/Root\s+(\d+)\s+(\d+)\s+R/', $text, $roots, PREG_SET_ORDER) < 1) {
            return null;
        }

        $root = end($roots);
        $catalog = $this->objectDictionary($text, (int) $root[1], (int) $root[2]);

        if ($catalog === null || preg_match('/\/Pages\s+(\d+)\s+(\d+)\s+R/', $catalog, $pages) !== 1) {
            return null;
        }

        $tree = $this->objectDictionary($text, (int) $pages[1], (int) $pages[2]);

        if ($tree === null || preg_match('/\/Count\s+(\d+)/', $tree, $count) !== 1) {
            return null;
        }

        return (int) $count[1];
    }

    /** 2. The largest /Count of a /Type /Pages dictionary, wherever it is written. */
    private function largestPagesCount(string $text): ?int
    {
        if (preg_match_all('/\/Type\s*\/Pages\b/', $text, $matches, PREG_OFFSET_CAPTURE) < 1) {
            return null;
        }

        $largest = null;

        foreach ($matches[0] as [, $at]) {
            $dictionary = $this->enclosingDictionary($text, $at);

            if ($dictionary !== null && preg_match('/\/Count\s+(\d+)/', $dictionary, $count) === 1) {
                $largest = max($largest ?? 0, (int) $count[1]);
            }
        }

        return $largest;
    }

    /** 3. The number of /Type /Page dictionaries (never /Pages). */
    private function leafCount(string $text): ?int
    {
        $count = preg_match_all('/\/Type\s*\/Page(?![A-Za-z])/', $text);

        return $count > 0 ? $count : null;
    }

    /** The dictionary of the LAST "n g obj" in the text (a later update replaces an earlier one). */
    private function objectDictionary(string $text, int $number, int $generation): ?string
    {
        if (preg_match_all('/(?<![0-9])'.$number.'\s+'.$generation.'\s+obj\b/', $text, $matches, PREG_OFFSET_CAPTURE) < 1) {
            return null;
        }

        $at = end($matches[0])[1];
        $open = strpos($text, '<<', $at);

        return $open === false || $open - $at > 200 ? null : $this->dictionaryFrom($text, $open);
    }

    /** The innermost << ... >> around a position. */
    private function enclosingDictionary(string $text, int $at): ?string
    {
        $depth = 0;

        for ($i = $at - 1, $stop = max(0, $at - 100000); $i >= $stop; $i--) {
            $pair = substr($text, $i, 2);

            if ($pair === '>>') {
                $depth++;
                $i--;
            } elseif ($pair === '<<') {
                if ($depth === 0) {
                    return $this->dictionaryFrom($text, $i);
                }
                $depth--;
                $i--;
            }
        }

        return null;
    }

    /** The balanced << ... >> starting at $open. */
    private function dictionaryFrom(string $text, int $open): ?string
    {
        $depth = 0;

        for ($i = $open, $stop = min(strlen($text), $open + 200000); $i < $stop - 1; $i++) {
            $pair = $text[$i].$text[$i + 1];

            if ($pair === '<<') {
                $depth++;
                $i++;
            } elseif ($pair === '>>') {
                $depth--;
                $i++;

                if ($depth === 0) {
                    return substr($text, $open, $i - $open + 1);
                }
            }
        }

        return null;
    }
}
