<?php

namespace App\Services\Assistant;

use App\Enums\AssistantCardType;
use Illuminate\Support\Facades\Log;

/**
 * Removes what Lumi must never say about a customer's own requests (Phase 7E-1), in English, Filipino and Taglish:
 *
 * - that it did something itself ("I booked / confirmed / cancelled / rescheduled", "I received your payment",
 *   "na-cancel ko na", "natanggap ko na ang bayad"): Lumi has no tools, so such a sentence is always false;
 * - a status or payment fact ("ready for pickup", "is confirmed", "is totally confirmed", "is completed", "fully paid",
 *   "bayad na") that the record the sentence is about does not show;
 * - a promise that the owner or studio will confirm, approve or accept a booking or request (7E-2c, PROMISES).
 *
 * Which record a sentence is about (Phase 7E-2a; before, any record of the customer was enough): a reference number in
 * the sentence (PR-/PB-, any case, with or without the hyphen; the nearest one before the claim) means that record
 * only, and a reference that is not one of the customer's own records is never verified; else a service or package
 * name of exactly the records it names; else "order" or "booking" words mean the customer's open records of that kind;
 * else every open record (every record when none is open). The claim stays only when ALL of those records show it, so a
 * generic "it is ready" is removed while the customer has a ready order and an unready booking.
 *
 * A question, a sentence that only says when something will happen ("once it is ready for pickup", "kapag
 * confirmed na"), or one that denies it ("PR-000101 is not ready for pickup yet", 7E-2a) is not a claim. Every sentence or list line holding a claim is removed; when nothing is left, a fixed
 * text points to My orders, My bookings and the studio. Deterministic: no model call.
 */
class ClaimGuard
{
    /** Lumi's own actions: never true. */
    private const ACTIONS = [
        '/\b(?:I|we)(?:\'ve|\'d| have| had)?\s+(?:already\s+|just\s+|now\s+|also\s+|(?:gone|went) ahead and\s+)*(?:booked|confirmed|cancell?ed|rescheduled|re-scheduled|reserved|approved|refunded|marked|moved)\b/iu',
        '/\b(?:I|we)(?:\'ve| have)?\s+(?:already\s+|just\s+)?(?:received|recorded|got|accepted)\s+(?:your\s+|the\s+)?(?:payment|bayad|GCash)\b/iu',
        '/\b(?:na|ni|i)-?(?:cancel|confirm|book|reschedule|resched|record|reserve)\s+(?:ko|namin)\b/iu',
        '/\b(?:kinansela|kinancel|kinumpirma|binook|nirecord|inireschedule|tinanggap|natanggap)\s+(?:ko|namin)\b/iu',
    ];

    /**
     * Up to two adverbs between the verb and the state word (7E-2c): "is totally confirmed", "is now really ready",
     * "has been officially completed". Words that only say how things usually go ("is usually confirmed", "is only
     * ready after") are not counted as adverbs here.
     */
    private const ADVERBS = '(?:(?!(?:only|usually|normally|typically|generally|rarely|early|family|daily|weekly|monthly|reply|apply|supply)\b)(?:\w+ly|really|definitely|surely|now|already|just|also|kindly|officially|totally)\s+){0,2}';

    /** Filipino words that may sit between the state word and "na" ("confirmed po na", "ready talaga na"). */
    private const FILIPINO_FILLERS = '(?:(?:po|talaga)\s+){0,2}';

    /** A status or payment fact, with the record state that makes it true. */
    private const STATES = [
        // "is confirmed by the owner", "is paid at the shop": how things work, not a record's state
        'confirmed' => '/\b(?:is|are|was|were|has been|have been|now|already|been)\s+'.self::ADVERBS.'confirmed\b(?!\s+(?:by|after|once|when)\b)|\b(?:confirmed|kumpirmado|na-?confirm)\s+'.self::FILIPINO_FILLERS.'na\b/iu',
        // "is ready" alone too (7E-2a), but not "ready to help you" or "ready for your questions"
        'ready' => '/\bready\s+(?:na\s+)?(?:for|to)\s+(?:be\s+)?pick(?:ed)?[\s-]?up\b|\bready\s+'.self::FILIPINO_FILLERS.'na\b|\bpu?we?de\s+(?:mo\s+|nyo\s+|niyo\s+)?na(?:ng)?\s+(?:kunin|i-?pick\s?up)\b|\b(?:is|are|now|already)\s+'.self::ADVERBS.'ready\b(?!\s+(?:to|for)\s+(?:help|assist|answer|book|you|your|any|questions?)\b)/iu',
        'completed' => '/\b(?:is|are|was|were|has been|have been|now|already)\s+'.self::ADVERBS.'(?:completed|complete|done|finished)\b|\b(?:completed|tapos)\s+'.self::FILIPINO_FILLERS.'na\b/iu',
        'paid' => '/\b(?:is|are|was|has been|have been|already)\s+'.self::ADVERBS.'fully\s+paid\b|\b(?:is|are|was|has been|have been|already)\s+'.self::ADVERBS.'paid\b(?!\s+(?:by|at|in|on|face|through|via|with|upon|for)\b)|\byou(?:\'ve| have)?\s+'.self::ADVERBS.'paid\b|\b(?:bayad|paid)\s+'.self::FILIPINO_FILLERS.'na\b|\bnabayaran\s+'.self::FILIPINO_FILLERS.'na\b/iu',
    ];

    /**
     * A promise that the owner or studio WILL confirm, approve or accept the customer's booking or request (7E-2c):
     * "The owner will confirm it there.", "we'll approve your booking", "your booking will be confirmed by the owner",
     * "kukumpirmahin ng owner", "i-a-approve nila", "tatanggapin nila ang request". The owner may reject it, so Lumi
     * never promises it. Not a promise: the price ("the studio will confirm the price"), a payment method ("the shop will
     * accept cash or GCash"), replying or reviewing ("the
     * studio will reply", "will review your request"), how things work in the present tense ("the owner confirms the
     * final price after review"), and, like every claim, a question, a condition or a negation.
     */
    private const PROMISES = [
        // 7E-2d: the object may be a price after up to four small words ("the exact final price"), not only two words
        '/\b(?:the\s+)?(?:owner|studio|shop|team|staff|we|they)\s*(?:will|\'ll|’ll|is going to|are going to|shall)\s+(?:(?:definitely|surely|certainly|also|then|just|soon|probably|likely|quickly|gladly)\s+)?(?:confirm|approve|accept)\b(?!(?:\s+\S+){0,2}?\s+'.self::PRICE_OBJECT.'|(?:\s+'.self::PRICE_FILLERS.'){0,4}\s+'.self::PRICE_OBJECT.')/iu',
        '/\b(?:will|\'ll|’ll|is going to|are going to)\s+be\s+(?:\w+ly\s+)?(?:confirmed|approved|accepted)\b/iu',
        // 7E-2d: in Filipino the doer comes between the verb and the object ("kukumpirmahin ng owner ang final na presyo")
        '/\b(?:kukumpirmahin|kokumpirmahin|ikukumpirma|i-?co-?confirm|aaprubahan|aaprobahan|i-?a-?approve|tatanggapin|i-?a-?accept)\b(?!(?:\s+\S+){0,3}?\s+'.self::PRICE_OBJECT.'|(?:\s+'.self::PRICE_FILLERS.'){0,6}\s+'.self::PRICE_OBJECT.')/iu',
    ];

    /** What a confirmation may be about that is no promise about a booking: a price or a way to pay. */
    private const PRICE_OBJECT = '(?:price|prices|amount|total|cost|quote|quotation|presyo|halaga|bayad|cash|gcash|payments?|cards?|files?)\b';

    /**
     * The small words that may sit between the verb and the price (7E-2d): articles, the doer, "final", "exact" and the
     * Filipino markers. A noun such as "booking" is not one of them, so "kukumpirmahin ng owner ang booking at presyo"
     * stays a promise.
     */
    private const PRICE_FILLERS = '(?:the|your|a|an|its|their|final|exact|total|correct|full|actual|official|last|pa|po|na|ng|ni|nila|niya|nina|ang|yung|ung|mga|owner|studio|shop|may-ari|huling|eksaktong|tamang|buong|kabuuang|totoong|opisyal)';

    /** A promise sentence is about the price when a price word comes before it in the same clause (7E-2d for Filipino). */
    private const PRICE_WORDS = '/\b(?:price|prices|amount|total|cost|quote|quotation|presyo|halaga)\b/iu';

    /** Words that make a sentence about the future or a condition, not a claim. */
    private const CONDITIONAL = '/\b(?:when|once|after|until|if|before|as soon as|kapag|pag|kung|bago|hanggang|pagka)\b/iu';

    /** A "not" right before the state words: "is not ready for pickup", "has not been confirmed" deny, never claim. */
    private const NEGATION = "/(?:\\b(?:not|never|no longer|hindi|di)\\b|n['’]t\\b)[^,;:]{0,20}$/iu";

    /** Words that point at one kind of record. */
    private const KIND_WORDS = [
        'order' => '/\b(?:orders?|prints?|printing|pa-?print|naprint|printout)\b/iu',
        'booking' => '/\b(?:bookings?|shoots?|photoshoots?|sessions?|appointments?|pictorial)\b/iu',
    ];

    public function guard(string $reply, CustomerRecords $records): string
    {
        $removed = 0;
        $lines = [];

        foreach (preg_split('/\R/u', $reply) as $line) {
            if (trim($line) === '') {
                $lines[] = '';

                continue;
            }

            $kept = [];

            foreach (preg_split('/(?<=[.?!])\s+/u', trim($line)) as $sentence) {
                if ($this->claims($sentence, $records)) {
                    $removed++;
                } else {
                    $kept[] = $sentence;
                }
            }

            if ($kept !== []) {
                $lines[] = implode(' ', $kept);
            }
        }

        if ($removed === 0) {
            return $reply;
        }

        Log::info('assistant.claims_removed', ['removed' => $removed]);
        $text = trim(preg_replace('/\n{3,}/u', "\n\n", implode("\n", $lines)) ?? '');

        return $text === '' ? config('assistant.messages.claim_unverified') : $text;
    }

    public function claims(string $sentence, CustomerRecords $records): bool
    {
        if (str_ends_with(rtrim($sentence), '?')) {
            return false;
        }

        foreach (self::ACTIONS as $pattern) {
            if (preg_match($pattern, $sentence)) {
                return true;
            }
        }

        foreach (self::PROMISES as $index => $pattern) {
            if (! preg_match($pattern, $sentence, $match, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            $before = substr($sentence, 0, $match[0][1]);
            // The clause the promise sits in: after the last comma, semicolon or "and"/"at"/"but"/"pero"
            $clause = (string) preg_replace('/^.*(?:[,;:]|\b(?:and|at|but|pero)\b)/isu', '', $before);
            $aboutPrice = match ($index) {
                1 => preg_match(self::PRICE_WORDS, $before) === 1,
                2 => preg_match(self::PRICE_WORDS, $clause) === 1,
                default => false,
            };

            if (! preg_match(self::CONDITIONAL, $before) && ! preg_match(self::NEGATION, $before) && ! $aboutPrice) {
                return true;
            }
        }

        foreach (self::STATES as $state => $pattern) {
            if (! preg_match($pattern, $sentence, $match, PREG_OFFSET_CAPTURE)
                || preg_match(self::CONDITIONAL, substr($sentence, 0, $match[0][1]))
                || preg_match(self::NEGATION, substr($sentence, 0, $match[0][1]))) {
                continue;
            }

            $about = $this->about($sentence, $match[0][1], $records);

            if ($about === [] || array_filter($about, fn (CustomerRecord $record) => ! in_array($state, $record->states, true)) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * The records a claim at this offset is about (see the class comment); empty when none can be named.
     *
     * @return list<CustomerRecord>
     */
    private function about(string $sentence, int $offset, CustomerRecords $records): array
    {
        $references = CustomerRecords::references($sentence);

        if ($references !== []) {
            $before = array_filter($references, fn (array $reference) => $reference['offset'] <= $offset);
            $reference = $before === [] ? $references[0] : end($before);
            $record = $records->byReference($reference['reference']);

            return $record === null ? [] : [$record];
        }

        $named = array_values(array_filter($records->records, fn (CustomerRecord $record) => mb_strlen($record->name) >= 3 && mb_stripos($sentence, $record->name) !== false));

        if ($named !== []) {
            return $named;
        }

        $kinds = array_keys(array_filter(self::KIND_WORDS, fn (string $pattern) => preg_match($pattern, $sentence) === 1));
        $pool = count($kinds) === 1
            ? array_values(array_filter($records->records, fn (CustomerRecord $record) => $record->type === ($kinds[0] === 'order' ? AssistantCardType::Order : AssistantCardType::Booking)))
            : $records->records;

        $open = array_values(array_filter($pool, fn (CustomerRecord $record) => ! $record->finished));

        return $open === [] ? $pool : $open;
    }
}
