<?php

namespace App\Support;

/**
 * Spacing cleanup for text a model wrote (Phase 7E-2c, extended in 7E-2e). Models sometimes put a special space between
 * words: a narrow no-break space (U+202F), a thin space (U+2009), a no-break space (U+00A0) or a zero-width space
 * (U+200B). Kept as they are, some fonts draw them with no visible gap, so "Nov 3" reads as "Nov3".
 *
 * spaces() turns every Unicode space separator (category Zs) and a tab into one normal space and collapses repeated
 * spaces. The invisible format and control characters ([ZW]: U+200B, U+200C, U+200D, U+2060, U+FEFF, and since 7E-2e
 * U+034F, U+061C, U+180E, U+2061-2064, the tag block U+E0000-E007F and the C0 and C1 controls except a line break and a
 * tab) are judged by their neighbours: next to a space, at either end, before or after an apostrophe or hyphen
 * ("don[ZW]'t", "e[ZW]-mail") or between two digits ("1[ZW]200") they are inside a word or redundant, and are removed;
 * between two other visible characters ("Nov[ZW]3", "don't[ZW]accept") they separated two words, and become a space,
 * so words are never joined that were apart. A soft hyphen (U+00AD) only marks where a word may break: it is always
 * removed. A zero-width joiner between two pictographs (the family emoji), a variation selector and the tag characters
 * of a flag emoji are kept.
 *
 * repair() (7E-2e) adds the missing space in three safe places only: after a comma, semicolon or colon that follows a
 * letter and is directly followed by a letter ("Messages,o tawagan", "Tue,Nov 3"); between a time and AM or PM
 * ("2:00PM"); and after ". ! ?" directly followed by a capitalised word when the word before ends in three lower case
 * letters ("Sundays.We"). A word holding "://", "@" or starting with "www." is never touched, and nothing else is: not
 * numbers ("₱1,200.00", "3:00"), not "e.g.", "U.S.", "No. 5", "PR-000123" or "file.PDF", and never a lower case word
 * that lost its space ("minutesfor") or camel case ("DocumentPrinting", "GCash"): those cannot be split safely.
 */
final class PlainText
{
    private const INVISIBLE = '/^[\x{200B}\x{200C}\x{200D}\x{2060}\x{FEFF}\x{034F}\x{061C}\x{180E}\x{2061}-\x{2064}\x{E0000}-\x{E007F}\x{00}-\x{08}\x{0B}-\x{1F}\x{7F}-\x{9F}]$/u';

    private const SOFT_HYPHEN = "\u{00AD}";

    public static function spaces(string $text): string
    {
        $text = str_replace(self::SOFT_HYPHEN, '', $text);
        $text = preg_replace('/[\p{Zs}\t]/u', ' ', $text) ?? $text;
        $text = self::invisible($text);

        return preg_replace('/ {2,}/u', ' ', $text) ?? $text;
    }

    /** The missing space in the three safe places (see the class comment). */
    public static function repair(string $text): string
    {
        return (string) preg_replace_callback('/\S+/u', function (array $match): string {
            $word = $match[0];

            if (preg_match('~://|@|^www\.~iu', $word)) {
                return $word;
            }

            $word = preg_replace('/(?<=\p{L}|[)\]])([,;:])(?=\p{L})/u', '$1 ', $word) ?? $word;
            $word = preg_replace('/(?<!\d)(\d{1,2}(?::\d{2})?)(AM|PM|am|pm)(?!\p{L})/u', '$1 $2', $word) ?? $word;

            return preg_replace('/(?<=\p{Ll}{3})([.!?])(?=\p{Lu}\p{Ll})/u', '$1 ', $word) ?? $word;
        }, $text);
    }

    public static function isInvisible(string $char): bool
    {
        return preg_match(self::INVISIBLE, $char) === 1;
    }

    private static function invisible(string $text): string
    {
        $chars = mb_str_split($text);
        $out = [];
        $count = count($chars);

        for ($i = 0; $i < $count; $i++) {
            $char = $chars[$i];

            if (! self::isInvisible($char)) {
                $out[] = $char;

                continue;
            }

            // The nearest visible neighbours, skipping a run of invisible characters
            $before = self::neighbour($chars, $i, -1);
            $after = self::neighbour($chars, $i, 1);

            // A joiner between two pictographs, and the tag characters of a flag emoji, belong to the emoji
            if ($char === "\u{200D}" && $before !== null && $after !== null && self::pictograph($before) && self::pictograph($after)) {
                $out[] = $char;

                continue;
            }

            if (preg_match('/^[\x{E0020}-\x{E007F}]$/u', $char) && $before !== null && self::pictograph($before)) {
                $out[] = $char;

                continue;
            }

            // Only the first of a run decides; the rest are dropped
            if ($i > 0 && self::isInvisible($chars[$i - 1])) {
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
            if (! self::isInvisible($chars[$i])) {
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
