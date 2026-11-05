<?php

namespace App\Services\Assistant\Eval;

/**
 * A small word-list heuristic for the language of a reply (Phase 7E-2b): "en", "fil" or "taglish". It counts common
 * function words of each language; a reply with almost only English ones is "en", almost only Filipino ones "fil",
 * and a real mix "taglish". Good enough to tell whether Lumi followed the customer's language, not a linguist.
 */
class LanguageDetector
{
    public const EN = 'en';

    public const FIL = 'fil';

    public const TAGLISH = 'taglish';

    private const FILIPINO = ['po', 'opo', 'ang', 'mga', 'ng', 'nang', 'sa', 'na', 'ay', 'ko', 'mo', 'niyo', 'nyo', 'ninyo', 'kayo', 'kami', 'namin',
        'natin', 'tayo', 'ba', 'ito', 'iyan', 'yan', 'yung', 'ung', 'lang', 'lamang', 'din', 'rin', 'pa', 'naman', 'hindi', 'wala', 'may', 'meron',
        'salamat', 'pwede', 'puwede', 'pwedeng', 'puwedeng', 'kung', 'para', 'siya', 'sila', 'ikaw', 'ka', 'nga', 'kasi', 'dahil', 'pero', 'talaga',
        'magkano', 'paano', 'saan', 'kailan', 'ano', 'anong', 'bakit', 'oo', 'sige', 'inyong', 'inyo', 'ating', 'sana', 'gusto', 'kailangan', 'ninyong',
        'namin', 'niya', 'kanila', 'amin', 'atin', 'muna', 'lahat', 'bawat', 'ngayon', 'bukas', 'kahapon', 'dito', 'doon', 'diyan', 'nasa', 'galing'];

    private const ENGLISH = ['the', 'is', 'are', 'you', 'your', 'we', 'our', 'can', 'will', 'to', 'for', 'and', 'of', 'a', 'an', 'it', 'this', 'that',
        'with', 'please', 'have', 'has', 'be', 'do', 'does', 'not', 'if', 'in', 'on', 'what', 'how', 'when', 'where', 'which', 'would', 'could',
        'there', 'here', 'i', 'my', 'me', 'us', 'they', 'from', 'by', 'or', 'any', 'all', 'yet', 'still', 'was', 'were', 'been', 'about', 'also'];

    public function detect(string $text): string
    {
        preg_match_all('/[\p{L}]+/u', mb_strtolower($text), $matches);

        $filipino = 0;
        $english = 0;

        foreach ($matches[0] as $word) {
            $filipino += in_array($word, self::FILIPINO, true) ? 1 : 0;
            $english += in_array($word, self::ENGLISH, true) ? 1 : 0;
        }

        if ($filipino + $english === 0) {
            return self::EN;
        }

        $share = $filipino / ($filipino + $english);

        return match (true) {
            $share < 0.2 => self::EN,
            $share > 0.65 => self::FIL,
            default => self::TAGLISH,
        };
    }
}
