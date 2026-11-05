<?php

namespace App\Services\Assistant\Eval;

use App\Support\PlainText;

/**
 * The glued-words diagnostic of one model reply (Phase 7E-2e). It never keeps text: only the non-ASCII characters of
 * the RAW reply (what ResponseValidator received) and of the FINAL reply, as code points with a count ("U+00AD x2"),
 * and for each glued-word hit of the final reply its offset with three characters of context on each side, also as code
 * points. A line break and a tab are not counted. Eval replies are fake-data replies, so their code points are safe.
 */
final class EvalCharacters
{
    public const CONTEXT = 3;

    /**
     * @param  list<string>  $glued  the no_glued_words hits of the final reply
     * @return array{raw: array<string, int>, final: array<string, int>, glue: list<array{offset: int, length: int, context: string}>}
     */
    public static function describe(?string $raw, string $final, array $glued): array
    {
        $hits = [];

        foreach ($glued as $word) {
            $needle = preg_match('/^U\+([0-9A-F]{4,6})$/', $word, $cp) ? mb_chr((int) hexdec($cp[1])) : $word;
            $offset = mb_strpos($final, $needle);

            if ($offset === false) {
                continue;
            }

            $start = max(0, $offset - self::CONTEXT);
            $length = mb_strlen($needle);
            $context = mb_substr($final, $start, $offset - $start + $length + self::CONTEXT);
            $hits[] = ['offset' => $offset, 'length' => $length, 'context' => self::codePoints($context)];
        }

        return ['raw' => $raw === null ? [] : self::unusual($raw), 'final' => self::unusual($final), 'glue' => $hits];
    }

    /** @return array<string, int> "U+00AD" => count, every character above U+007E and every control but \n and \t */
    public static function unusual(string $text): array
    {
        $found = [];

        foreach (mb_str_split($text) as $char) {
            $code = mb_ord($char);

            if (($code > 0x7E || $code < 0x20) && $char !== "\n" && $char !== "\t") {
                $key = sprintf('U+%04X', $code);
                $found[$key] = ($found[$key] ?? 0) + 1;
            }
        }

        ksort($found);

        return $found;
    }

    /**
     * Only the characters PlainText exists for: a space separator other than the normal space, a soft hyphen, and the
     * invisible format and control characters. The summary line of the report counts these.
     *
     * @param  array<string, int>  $counts  from unusual()
     * @return array<string, int>
     */
    public static function suspicious(array $counts): array
    {
        return array_filter($counts, function (string $code) {
            $char = mb_chr((int) hexdec(substr($code, 2)));

            return $char === "\u{00AD}" || PlainText::isInvisible($char) || preg_match('/^\p{Zs}$/u', $char) === 1;
        }, ARRAY_FILTER_USE_KEY);
    }

    public static function codePoints(string $text): string
    {
        return implode(' ', array_map(fn (string $char) => sprintf('U+%04X', mb_ord($char)), mb_str_split($text)));
    }

    /** @param array<string, int> $counts */
    public static function format(array $counts): string
    {
        return $counts === [] ? '-' : implode(', ', array_map(fn (string $code, int $count) => "{$code} x{$count}", array_keys($counts), $counts));
    }
}
