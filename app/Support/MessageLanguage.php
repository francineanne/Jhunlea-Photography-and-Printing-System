<?php

namespace App\Support;

/**
 * The language of one message by a small word-list heuristic (Phase 7E-2d, moved from the eval's LanguageDetector):
 * "en", "fil", "taglish" or "unknown". It counts common function words of each language: almost only English ones is
 * "en", almost only Filipino ones "fil", a real mix "taglish". Fewer than MIN_MARKERS such words ("hi", "📸", "3500",
 * "ok po") is "unknown": too little to say. Good enough to tell the model which language to answer in, not a linguist.
 */
final class MessageLanguage
{
    public const EN = 'en';

    public const FIL = 'fil';

    public const TAGLISH = 'taglish';

    public const UNKNOWN = 'unknown';

    public const MIN_MARKERS = 2;

    private const FILIPINO = ['po', 'opo', 'ang', 'mga', 'ng', 'nang', 'sa', 'na', 'ay', 'ko', 'mo', 'niyo', 'nyo', 'ninyo', 'kayo', 'kami', 'namin',
        'natin', 'tayo', 'ba', 'ito', 'iyan', 'yan', 'yung', 'ung', 'lang', 'lamang', 'din', 'rin', 'pa', 'naman', 'hindi', 'wala', 'may', 'meron',
        'salamat', 'pwede', 'puwede', 'pwedeng', 'puwedeng', 'kung', 'para', 'siya', 'sila', 'ikaw', 'ka', 'nga', 'kasi', 'dahil', 'pero', 'talaga',
        'magkano', 'paano', 'saan', 'kailan', 'ano', 'anong', 'bakit', 'oo', 'sige', 'inyong', 'inyo', 'ating', 'sana', 'gusto', 'kailangan', 'ninyong',
        'niya', 'kanila', 'amin', 'atin', 'muna', 'lahat', 'bawat', 'ngayon', 'bukas', 'kahapon', 'dito', 'doon', 'diyan', 'nasa', 'galing',
        'kumusta', 'kamusta', 'alin', 'ilan', 'ilang', 'yun', 'eh', 'daw', 'raw', 'kay', 'kina', 'nyo', 'kau', 'pde', 'nman', 'lng'];

    private const ENGLISH = ['the', 'is', 'are', 'you', 'your', 'we', 'our', 'can', 'will', 'to', 'for', 'and', 'of', 'a', 'an', 'it', 'this', 'that',
        'with', 'please', 'have', 'has', 'be', 'do', 'does', 'not', 'if', 'in', 'on', 'what', 'how', 'when', 'where', 'which', 'would', 'could',
        'there', 'here', 'i', 'my', 'me', 'us', 'they', 'from', 'by', 'or', 'any', 'all', 'yet', 'still', 'was', 'were', 'been', 'about', 'also',
        'one', 'much', 'many', 'did', 'am', 'its', 'should', 'want', 'need', 'thanks', 'thank'];

    public static function detect(string $text): string
    {
        preg_match_all('/[\p{L}]+/u', mb_strtolower($text), $matches);

        $filipino = 0;
        $english = 0;

        foreach ($matches[0] as $word) {
            $filipino += in_array($word, self::FILIPINO, true) ? 1 : 0;
            $english += in_array($word, self::ENGLISH, true) ? 1 : 0;
        }

        if ($filipino + $english < self::MIN_MARKERS) {
            return self::UNKNOWN;
        }

        $share = $filipino / ($filipino + $english);

        return match (true) {
            $share < 0.2 => self::EN,
            $share > 0.65 => self::FIL,
            default => self::TAGLISH,
        };
    }
}
