<?php

namespace App\Services\Assistant\Eval;

use App\Support\MessageLanguage;

/**
 * The eval's language check of a REPLY (Phase 7E-2b), now a thin user of the production MessageLanguage (7E-2d):
 * "en", "fil" or "taglish". A reply with too few marker words to tell (a price, an emoji) counts as "en", as before.
 */
class LanguageDetector
{
    public const EN = MessageLanguage::EN;

    public const FIL = MessageLanguage::FIL;

    public const TAGLISH = MessageLanguage::TAGLISH;

    public function detect(string $text): string
    {
        $language = MessageLanguage::detect($text);

        return $language === MessageLanguage::UNKNOWN ? self::EN : $language;
    }
}
