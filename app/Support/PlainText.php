<?php

namespace App\Support;

/**
 * Spacing cleanup for text a model wrote (Phase 7E-2c). Models sometimes put a special space between words: a
 * narrow no-break space (U+202F), a thin space (U+2009), a no-break space (U+00A0) or a zero-width space (U+200B). Kept
 * as they are, some fonts draw them with no visible gap, so "Nov 3" or "don't accept" reads as "Nov3" or "don'taccept".
 *
 * spaces() turns every Unicode space separator (category Zs) and a tab into one normal space and collapses repeated
 * spaces. The invisible format characters (U+200B, U+200C, U+200D, U+2060, U+FEFF) are judged by their neighbours:
 * next to a space, at either end, before or after an apostrophe or hyphen ("don[ZW]'t", "e[ZW]-mail") or between two
 * digits ("1[ZW]200") they are inside a word or redundant, and are removed; between two other visible characters
 * ("Nov[ZW]3", "don't[ZW]accept") they separated two words, and become a space, so words are never joined that were
 * apart ([ZW] = one of those invisible characters). A zero-width
 * joiner between two pictographs (the family emoji) is kept. Line breaks, emoji, Filipino letters and punctuation are
 * left alone.
 */
final class PlainText
{
    private const INVISIBLE = "\u{200B}\u{200C}\u{200D}\u{2060}\u{FEFF}";

    public static function spaces(string $text): string
    {
        $text = preg_replace('/[\p{Zs}\t]/u', ' ', $text) ?? $text;
        $text = self::invisible($text);

        return preg_replace('/ {2,}/u', ' ', $text) ?? $text;
    }

    private static function invisible(string $text): string
    {
        $chars = mb_str_split($text);
        $out = [];
        $count = count($chars);

        for ($i = 0; $i < $count; $i++) {
            $char = $chars[$i];

            if (! str_contains(self::INVISIBLE, $char)) {
                $out[] = $char;

                continue;
            }

            // The nearest visible neighbours, skipping a run of invisible characters
            $before = self::neighbour($chars, $i, -1);
            $after = self::neighbour($chars, $i, 1);

            if ($char === "\u{200D}" && $before !== null && $after !== null && self::pictograph($before) && self::pictograph($after)) {
                $out[] = $char;

                continue;
            }

            // Only the first of a run decides; the rest are dropped
            if ($i > 0 && str_contains(self::INVISIBLE, $chars[$i - 1])) {
                continue;
            }

            if (self::separates($before, $after)) {
                $out[] = ' ';
            }
        }

        return implode('', $out);
    }

    /** @param list<string> $chars */
    private static function neighbour(array $chars, int $index, int $step): ?string
    {
        for ($i = $index + $step; $i >= 0 && $i < count($chars); $i += $step) {
            if (! str_contains(self::INVISIBLE, $chars[$i])) {
                return $chars[$i];
            }
        }

        return null;
    }

    /** True when the two visible characters around an invisible one belong to two separate words. */
    private static function separates(?string $before, ?string $after): bool
    {
        if ($before === null || $after === null || preg_match('/\s/u', $before.$after)) {
            return false;
        }

        $glue = "/^['’\\-]$/u";

        if (preg_match($glue, $before) || preg_match($glue, $after)) {
            return false;
        }

        // Inside a number: 1[ZW]200, 1,[ZW]200, 3.[ZW]50
        if (preg_match('/^[\d.,]$/u', $before) && preg_match('/^\d$/u', $after) || preg_match('/^\d$/u', $before) && preg_match('/^[.,]$/u', $after)) {
            return false;
        }

        return true;
    }

    private static function pictograph(string $char): bool
    {
        return preg_match('/[\p{So}\x{FE0F}\x{1F3FB}-\x{1F3FF}]/u', $char) === 1;
    }
}
