<?php

namespace App\Support;

/**
 * Phase 10R: the "Custom pages" text of the printing wizard, such as "1-5, 8". Digits, commas, spaces and ranges a-b
 * with a <= b; every page at least 1 and at most config('printing.max_pages'); a page named twice counts once.
 * The same rule is written in resources/js/app.js (parsePageRange) for the live count; the server decides.
 */
final class PageRange
{
    public const MAX_LENGTH = 200;

    /** @param  list<int>  $pages  sorted, unique */
    private function __construct(public readonly array $pages, public readonly string $text) {}

    /** The parsed range, or null when the text is not a valid list of pages. */
    public static function parse(mixed $input): ?self
    {
        if (! is_string($input)) {
            return null;
        }

        $input = trim($input);

        if ($input === '' || mb_strlen($input) > self::MAX_LENGTH || preg_match('/^[0-9,\-\s]+$/', $input) !== 1) {
            return null;
        }

        $max = (int) config('printing.max_pages');
        $pages = [];
        $parts = [];

        foreach (explode(',', $input) as $part) {
            $part = trim($part);

            if ($part === '') {
                return null;
            }

            if (preg_match('/^(\d+)$/', $part, $one) === 1) {
                [$from, $to] = [(int) $one[1], (int) $one[1]];
            } elseif (preg_match('/^(\d+)\s*-\s*(\d+)$/', $part, $two) === 1) {
                [$from, $to] = [(int) $two[1], (int) $two[2]];
            } else {
                return null;
            }

            if ($from < 1 || $from > $to || $to > $max) {
                return null;
            }

            $parts[] = $from === $to ? (string) $from : $from.'-'.$to;

            for ($page = $from; $page <= $to; $page++) {
                $pages[$page] = true;
            }
        }

        $pages = array_keys($pages);
        sort($pages);

        return new self($pages, implode(', ', $parts));
    }

    public function count(): int
    {
        return count($this->pages);
    }

    public function highest(): int
    {
        return $this->pages === [] ? 0 : $this->pages[count($this->pages) - 1];
    }
}
