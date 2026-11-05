<?php

namespace App\Services\Assistant;

/**
 * The last cleanup of a model reply after the guards (Phase 7E-2e). A reply whose last line ends with a colon and
 * nothing after it ("Here are some of our popular options:") promised a list it never gave: that trailing sentence is
 * removed. A colon in the middle, before a list on the next line, or in a time ("3:00") stays. When nothing is left the
 * given fixed text is used. Deterministic, and it never changes a word, a number or a reference.
 */
final class ReplyCleanup
{
    public static function finish(string $text, string $fallback): string
    {
        $lines = preg_split('/\R/u', rtrim($text)) ?: [];

        while ($lines !== [] && trim((string) end($lines)) === '') {
            array_pop($lines);
        }

        if ($lines === []) {
            return $text;
        }

        $last = rtrim((string) array_pop($lines));

        if (! str_ends_with($last, ':') && ! str_ends_with($last, '：')) {
            return $text;
        }

        $sentences = preg_split('/(?<=[.!?])\s+/u', trim($last)) ?: [];
        array_pop($sentences);
        $kept = trim(implode(' ', $sentences));

        if ($kept !== '') {
            $lines[] = $kept;
        }

        $result = trim(implode("\n", $lines));
        app(GuardStats::class)->removed(GuardStats::DANGLING_COLON, 1, $result === '');

        return $result === '' ? $fallback : $result;
    }
}
