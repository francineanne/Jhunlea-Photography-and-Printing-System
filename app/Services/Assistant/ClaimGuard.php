<?php

namespace App\Services\Assistant;

use Illuminate\Support\Facades\Log;

/**
 * Removes what Lumi must never say about a customer's own requests (Phase 7E-1), in English, Filipino and Taglish:
 *
 * - that it did something itself ("I booked / confirmed / cancelled / rescheduled", "I received your payment",
 *   "na-cancel ko na", "natanggap ko na ang bayad"): Lumi has no tools, so such a sentence is always false;
 * - a status or payment fact no record of this customer shows ("ready for pickup", "is confirmed", "is completed",
 *   "fully paid", "bayad na"): kept only when one of the customer's own records is in that state.
 *
 * A question, or a sentence that only says when something will happen ("once it is ready for pickup", "kapag
 * confirmed na"), is not a claim. Every sentence or list line holding a claim is removed; when nothing is left, a fixed
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

    /** A status or payment fact, with the record state that makes it true. */
    private const STATES = [
        // "is confirmed by the owner", "is paid at the shop": how things work, not a record's state
        'confirmed' => '/\b(?:is|are|was|were|has been|have been|now|already|been)\s+(?:now\s+|already\s+)?confirmed\b(?!\s+(?:by|after|once|when)\b)|\b(?:confirmed|kumpirmado|na-?confirm)\s+na\b/iu',
        'ready' => '/\bready\s+(?:na\s+)?(?:for|to)\s+(?:be\s+)?pick(?:ed)?[\s-]?up\b|\bready\s+na\b|\bpu?we?de\s+(?:mo\s+|nyo\s+|niyo\s+)?na(?:ng)?\s+(?:kunin|i-?pick\s?up)\b/iu',
        'completed' => '/\b(?:is|are|was|were|has been|have been|now|already)\s+(?:now\s+|already\s+)?(?:completed|complete|done|finished)\b|\b(?:completed|tapos)\s+na\b/iu',
        'paid' => '/\b(?:is|are|was|has been|have been|already)\s+(?:already\s+)?fully\s+paid\b|\b(?:is|are|was|has been|have been|already)\s+(?:already\s+)?paid\b(?!\s+(?:by|at|in|on|face|through|via|with|upon|for)\b)|\byou(?:\'ve| have)?\s+(?:already\s+)?paid\b|\b(?:bayad|paid)\s+na\b|\bnabayaran\s+na\b/iu',
    ];

    /** Words that make a sentence about the future or a condition, not a claim. */
    private const CONDITIONAL = '/\b(?:when|once|after|until|if|before|as soon as|kapag|pag|kung|bago|hanggang|pagka)\b/iu';

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

        foreach (self::STATES as $state => $pattern) {
            if (preg_match($pattern, $sentence, $match, PREG_OFFSET_CAPTURE) && ! $records->shows($state)
                && ! preg_match(self::CONDITIONAL, substr($sentence, 0, $match[0][1]))) {
                return true;
            }
        }

        return false;
    }
}
