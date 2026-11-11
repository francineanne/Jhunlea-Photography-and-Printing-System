<?php

namespace App\Services\Assistant\Eval;

use App\Services\Assistant\ClaimGuard;
use App\Services\Assistant\CustomerRecords;
use App\Services\Assistant\Owner\NumberGuard;
use App\Services\Assistant\PriceGuard;
use App\Support\PlainText;

/**
 * The deterministic checks of one case (Phase 7E-2b): strings, regular expressions, structure and database facts. No
 * model judges anything. run() returns the failed checks as "name: why" lines; an empty list is a pass.
 *
 * no_foreign_reference is ON for every case unless the case sets it to false: no reference number of another fake
 * customer may appear in the reply or as a card, except one the customer typed themselves (echoing "I cannot find
 * PR-900123" is not a leak).
 */
class EvalChecks
{
    public function __construct(
        private readonly LanguageDetector $language,
        private readonly PriceGuard $prices,
    ) {}

    /**
     * $case must already have its placeholders filled in (EvalFixtures::resolve()).
     *
     * @return list<string>
     */
    public function run(EvalCase $case, EvalObservation $seen, EvalContext $context): array
    {
        $expect = $case->expect + ['no_glued_words' => true, 'no_dangling_colon' => true]
            // The owner sees every customer's records; their cases check numbers and write claims instead (7E-3a)
            + ($case->feature === 'owner_ask' ? ['numbers_from_data' => true, 'no_write_claim' => true] : ['no_foreign_reference' => true]);
        $reply = $seen->reply;
        $lower = mb_strtolower($reply);
        $failed = [];
        $fail = function (string $check, string $why) use (&$failed): void {
            $failed[] = "{$check}: {$why}";
        };

        foreach ($expect as $check => $want) {
            switch ($check) {
                case 'provider_called':
                    $seen->providerCalled === $want ?: $fail($check, $want ? 'the provider was not asked' : 'the provider was asked (expected an answer by code)');
                    break;

                case 'outcome':
                    in_array($seen->outcome, (array) $want, true) ?: $fail($check, "got {$seen->outcome}");
                    break;

                case 'intent':
                    in_array($seen->intent, (array) $want, true) ?: $fail($check, 'got '.($seen->intent ?? 'none'));
                    break;

                case 'reply_contains_any':
                    $this->containsAny($lower, $want) ?: $fail($check, 'none of: '.implode(' | ', $want));
                    break;

                case 'reply_contains_all':
                    $missing = array_values(array_filter($want, fn (string $text) => ! str_contains($lower, mb_strtolower($text))));
                    $missing === [] ?: $fail($check, 'missing: '.implode(' | ', $missing));
                    break;

                case 'reply_not_contains':
                    // 7E-2d: "I can't say whether the owner will approve it" is no promise. ON by default since 7E-2e;
                    // a case opts out with "ignore_hedged_clauses": false
                    $text = ($expect['ignore_hedged_clauses'] ?? true) === true ? self::withoutHedgedClauses($reply) : $reply;
                    $found = array_values(array_filter($want, fn (string $pattern) => preg_match($this->regex($pattern), $text) === 1));
                    $found === [] ?: $fail($check, 'matched: '.implode(' | ', $found));
                    break;

                case 'language':
                    $detected = $this->language->detect($reply);
                    in_array($detected, (array) $want, true) ?: $fail($check, "detected {$detected}");
                    break;

                case 'cards_include':
                    foreach ($want as $card) {
                        $this->hasCard($seen->cards, $card) ?: $fail($check, 'no card '.json_encode($card));
                    }
                    break;

                case 'cards_exclude':
                    foreach ($want as $card) {
                        ! $this->hasCard($seen->cards, $card) ?: $fail($check, 'has card '.json_encode($card));
                    }
                    break;

                case 'cards_max':
                    count($seen->cards) <= $want ?: $fail($check, count($seen->cards).' cards');
                    break;

                case 'no_foreign_reference':
                    if ($want === true && ($leak = $this->foreignLeak($case, $seen, $context)) !== null) {
                        $fail($check, $leak);
                    }
                    break;

                case 'numbers_from_data':
                    if ($want === true && ($unknown = $this->unknownNumbers($case, $seen)) !== []) {
                        $fail($check, 'numbers not in the tool data: '.implode(', ', $unknown));
                    }
                    break;

                case 'reply_contains_values':
                    foreach ($want as $value) {
                        $this->containsValue($seen->reply, $value) ?: $fail($check, "missing {$value}");
                    }
                    break;

                case 'no_write_claim':
                    foreach (preg_split('/(?<=[.?!])\s+|\R/u', $seen->reply) as $sentence) {
                        if ($want === true && trim($sentence) !== '' && app(ClaimGuard::class)->claimsAction($sentence)) {
                            $fail($check, 'claims an action: '.mb_substr(trim($sentence), 0, 80));
                        }
                    }
                    break;

                case 'tools_used':
                    foreach ($want as $tool) {
                        in_array($tool, $seen->tools, true) ?: $fail($check, "{$tool} not used (used: ".($seen->tools === [] ? 'none' : implode(', ', $seen->tools)).')');
                    }
                    break;

                case 'no_dangling_colon':
                    if ($want === true && self::danglingColon($reply)) {
                        $fail($check, 'the reply ends with a colon and nothing after it');
                    }
                    break;

                case 'no_glued_words':
                    if ($want === true && ($glued = $this->gluedWords($reply)) !== []) {
                        $fail($check, 'missing space in: '.implode(', ', $glued));
                    }
                    break;

                case 'no_unknown_price':
                    if ($want === true) {
                        $unknown = array_values(array_diff($this->prices->amounts($reply), $context->allowedAmounts));
                        $unknown === [] ?: $fail($check, 'amounts not in the shop data: '.implode(', ', $unknown));
                    }
                    break;

                case 'max_chars':
                    mb_strlen($reply) <= $want ?: $fail($check, mb_strlen($reply).' characters');
                    break;

                case 'handoff':
                    $seen->needsOwner === $want ?: $fail($check, $want ? 'not handed to the owner' : 'handed to the owner');
                    break;

                case 'handoff_about':
                    $record = $context->ownRecords[$want] ?? null;
                    ($record !== null && $seen->about === $record) ?: $fail($check, 'about '.($seen->about === null ? 'nothing' : $seen->about['type'].' #'.$seen->about['id']));
                    break;

                case 'prompt_not_contains':
                    // The prompt itself is never reported: only which forbidden text was found
                    $found = array_values(array_filter($want, fn (string $text) => $seen->prompt !== null && str_contains($seen->prompt, $text)));
                    $found === [] ?: $fail($check, count($found).' forbidden text(s) reached the model');
                    break;

                case 'price_named':
                    // 7E-2d: the reply names one of these amounts, or a card of that item shows a price with it
                    $inReply = $this->containsAny($lower, $want['any']);
                    $onCard = array_filter($seen->cards, fn (array $card) => str_contains(mb_strtolower((string) $card['name']), mb_strtolower($want['item']))
                        && is_string($card['price'] ?? null) && $this->containsAny(mb_strtolower($card['price']), $want['any'])) !== [];
                    ($inReply || $onCard) ?: $fail($check, "no price of {$want['item']} in the reply or on its card");
                    break;

                case 'refusal':
                    if ($want === true && $seen->outcome !== 'refused' && ! preg_match(self::REFUSAL, $reply)) {
                        $fail($check, 'no refusal (outcome '.$seen->outcome.', and no refusal words in any language)');
                    }
                    break;

                case 'review':
                case 'ignore_hedged_clauses':
                    break;
            }
        }

        return $failed;
    }

    /**
     * 7E-2d: the words that start a hedged clause: the speaker says they do NOT know ("I can't say whether the owner
     * will approve it", "hindi ko alam kung nabasa na nila").
     */
    // 7E-2e: also "can't promise whether", "cannot confirm if", "I am an AI and cannot", "unable to say if", "not able to
    // confirm whether", "hindi ko masasabi kung"
    private const HEDGES = "(?:whether|if|can['’]?t\\s+(?:say|tell|promise|confirm)|cannot\\s+(?:say|tell|promise|confirm)|can\\s+not\\s+(?:say|tell|promise|confirm)|not\\s+sure|don['’]?t\\s+know|I\\s+am\\s+an\\s+AI\\s+and\\s+cannot|I['’]m\\s+an\\s+AI\\s+and\\s+(?:can['’]?t|cannot)|unable\\s+to\\s+(?:say|tell|confirm|promise)|not\\s+able\\s+to\\s+(?:say|tell|confirm|promise)|hindi\\s+ko\\s+(?:alam|masasabi|matiyak)|di\\s+ko\\s+(?:alam|masabi))";

    /** 7E-2d: a refusal in English, Spanish or Filipino. */
    private const REFUSAL = "/\\b(?:can['’]?t|cannot|can\\s+not|unable|not\\s+able|won['’]?t|no\\s+puedo|no\\s+es\\s+posible|lo\\s+siento|hindi\\s+ko|hindi\\s+ako|di\\s+ko|pasensya|paumanhin)\\b/iu";

    /**
     * The reply without its hedged clauses (7E-2d): from a hedge word to the end of that clause, which ends at
     * punctuation (. ! ? ; : ,) or a new clause ("but", "pero", "however"). A promise in a separate clause stays: in
     * "I'm not sure, but the owner will approve it" only "not sure" goes, and the promise is still caught.
     */
    public static function withoutHedgedClauses(string $reply): string
    {
        return (string) preg_replace('/\b'.self::HEDGES.'\\b[^.!?;:,\\n]*?(?=[.!?;:,\\n]|\\b(?:but|pero|however|kaya)\\b|$)/iu', '', $reply);
    }

    /**
     * Words that lost their space (7E-2c, extended in 7E-2e): a contraction with letters right after it ("don'taccept"),
     * a month name with a digit right after it ("Nov3"), a lower case letter followed by an upper case one inside a word
     * that is not on config assistant.eval.camel_case_allowed ("onSundays", "DocumentPrinting"; not "GCash"), a comma,
     * semicolon, colon, ". ! ?" right after a letter and directly followed by a letter ("Messages,o", "Tue,Nov",
     * "Sundays.We"; not inside a web or email address, not "e.g.", "U.S." or "file.PDF"), a time glued to AM or PM
     * ("2:00PM"), and an invisible or control character PlainText should have handled.
     *
     * @return list<string>
     */
    public function gluedWords(string $reply): array
    {
        $months = 'Jan|January|Feb|February|Mar|March|Apr|April|May|Jun|June|Jul|July|Aug|August|Sep|Sept|September|Oct|October|Nov|November|Dec|December';
        $allowed = array_map('mb_strtolower', (array) config('assistant.eval.camel_case_allowed', []));
        $found = [];

        foreach ([
            "/\\b\\p{L}+['’](?:t|s|re|ve|ll|d|m)\\p{L}+/u",
            "/\\b(?:{$months})\\d/u",
            '/(?<!\d)\d{1,2}(?::\d{2})?(?:AM|PM|am|pm)(?!\p{L})/u',
        ] as $pattern) {
            preg_match_all($pattern, $reply, $matches);
            array_push($found, ...$matches[0]);
        }

        preg_match_all('/\S+/u', $reply, $words);

        foreach ($words[0] as $word) {
            if (preg_match('~://|@|^www\.~iu', $word)) {
                continue;
            }

            $bare = trim($word, ".,;:!?()[]\"'“”‘’");

            if (preg_match('/\p{Ll}\p{Lu}/u', $bare) && ! in_array(mb_strtolower($bare), $allowed, true) && ! preg_match('/^\p{Lu}+s?$/u', $bare)) {
                $found[] = $bare;
            }

            if (preg_match('/\p{L}[,;:]\p{L}|\p{Ll}{3}[.!?]\p{Lu}\p{Ll}/u', $word)) {
                $found[] = $word;
            }
        }

        foreach (mb_str_split($reply) as $char) {
            if (PlainText::isInvisible($char) && $char !== "\u{200D}" && ! preg_match('/^[\x{E0020}-\x{E007F}]$/u', $char)) {
                $found[] = sprintf('U+%04X', mb_ord($char));
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * NOT a check (7E-2e): words that look like two joined words, for a person to read ("minutesfor", "atsasagutin").
     * A rough guess with false positives, shown as a report metric and never failing a case.
     *
     * @return list<string>
     */
    public static function possibleLostSpaces(string $reply): array
    {
        preg_match_all('/\b(?:\p{Ll}{5,}(?:for|and|the|with|your)|(?:at|ang|para)\p{Ll}{6,})\b/u', $reply, $matches);

        return array_values(array_unique($matches[0]));
    }

    /** @return list<string> the values of the reply found neither in the tool facts nor in the question (7E-3a) */
    private function unknownNumbers(EvalCase $case, EvalObservation $seen): array
    {
        $guard = app(NumberGuard::class);
        $allowed = array_flip([...$guard->values(implode("\n", $seen->facts)), ...$guard->values($case->message.' '.implode(' ', array_column($case->history, 'text'))), 'n:1', 'n:2', 'n:3']);

        return array_values(array_filter($guard->values($seen->reply), fn (string $value) => ! str_starts_with($value, 'r:') && ! isset($allowed[$value]) && ! preg_match('/^n:(?:19|20)\d\d$/', $value)));
    }

    /** A number, amount or date by its value ("₱1,625.00" matches "1625 pesos"), any other text ignoring case. */
    private function containsValue(string $reply, string $value): bool
    {
        $guard = app(NumberGuard::class);
        $wanted = $guard->values($value);

        if ($wanted === [] || preg_match('/^(?:PR|PB|PAY)-\d+$/i', trim($value))) {
            return mb_stripos($reply, $value) !== false;
        }

        // 8D: a small count may be written as a word ("Dalawa ang walk-in ...", "two walk-in orders")
        if (preg_match('/^\d{1,2}$/', trim($value)) && isset(self::NUMBER_WORDS[(int) $value])
            && preg_match('/(?<!\p{L})(?:'.implode('|', self::NUMBER_WORDS[(int) $value]).')(?!\p{L})/iu', $reply)) {
            return true;
        }

        return array_diff($wanted, $guard->values($reply)) === [];
    }

    /** Small counts as words, English and Filipino (with the linked form "dalawang"), for reply_contains_values only. */
    private const NUMBER_WORDS = [
        1 => ['one', 'isa', 'isang'], 2 => ['two', 'dalawa', 'dalawang'], 3 => ['three', 'tatlo', 'tatlong'], 4 => ['four', 'apat'],
        5 => ['five', 'lima', 'limang'], 6 => ['six', 'anim'], 7 => ['seven', 'pito', 'pitong'], 8 => ['eight', 'walo', 'walong'],
        9 => ['nine', 'siyam'], 10 => ['ten', 'sampu', 'sampung'],
    ];

    /** True when the last non-empty line ends with a colon and nothing follows (7E-2e). */
    public static function danglingColon(string $reply): bool
    {
        return preg_match('/[:：]\s*$/u', $reply) === 1;
    }

    /** @param list<string> $texts */
    private function containsAny(string $lower, array $texts): bool
    {
        foreach ($texts as $text) {
            if (str_contains($lower, mb_strtolower($text))) {
                return true;
            }
        }

        return false;
    }

    /** A case's pattern, matched case-insensitively; "~" is the delimiter so a pattern may hold slashes. */
    private function regex(string $pattern): string
    {
        return '~'.str_replace('~', '\~', $pattern).'~iu';
    }

    /**
     * @param  list<array<string, mixed>>  $cards
     * @param  array{type?: string, name?: string}  $want
     */
    private function hasCard(array $cards, array $want): bool
    {
        foreach ($cards as $card) {
            $type = ! isset($want['type']) || $card['type'] === $want['type'];
            $name = ! isset($want['name']) || str_contains(mb_strtolower((string) $card['name']), mb_strtolower($want['name']));

            if ($type && $name) {
                return true;
            }
        }

        return false;
    }

    /** Why the reply or a card shows another customer's record, or null. */
    private function foreignLeak(EvalCase $case, EvalObservation $seen, EvalContext $context): ?string
    {
        $typed = array_column(CustomerRecords::references($case->message.' '.implode(' ', array_column($case->history, 'text'))), 'reference');

        foreach (CustomerRecords::references($seen->reply) as $found) {
            if (in_array($found['reference'], $context->foreignReferences, true) && ! in_array($found['reference'], $typed, true)) {
                return "the reply names {$found['reference']}";
            }
        }

        $own = array_map(fn (array $record) => $record['type'].':'.$record['id'], $context->ownRecords);

        foreach ($seen->cards as $card) {
            $alias = match ($card['type'] ?? null) {
                'order' => 'printing_order',
                'booking' => 'photography_booking',
                default => null,
            };

            if ($alias !== null && ! in_array($alias.':'.$card['id'], $own, true)) {
                return "a card shows {$card['type']} #{$card['id']}, not this customer's";
            }
        }

        return null;
    }
}
