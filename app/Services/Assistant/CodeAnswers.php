<?php

namespace App\Services\Assistant;

use App\Models\AvailabilityRule;
use App\Support\ShopSettings;
use Illuminate\Support\Carbon;

/**
 * Answers a few short, clear messages without calling a provider (Phase 7E-2a): a greeting, thanks or goodbye, the
 * opening hours or "are you open now", and the shop's contact details or address. Deterministic: the reply is built
 * from the database (the hours, the blocked dates, the business info) and LiveFacts, never invented.
 *
 * A message is answered here only when it is at most config assistant.code_answers.max_chars long and EVERY word of it
 * belongs to ONE of these intents (greeting words may come with any of them). A service, a price, an order, a
 * booking, a second question or any unknown word sends it to the model; so does a needed fact that is not set (no
 * hours at all, an address that was never entered). When in doubt: null, and the model answers.
 *
 * The reply is in Filipino when the message has a clear Filipino word, otherwise in English.
 */
class CodeAnswers
{
    public const GREETING = 'greeting';

    public const THANKS = 'thanks';

    public const HOURS = 'hours';

    public const CONTACT = 'contact';

    private const GREETING_CORE = ['hi', 'hii', 'hiii', 'hello', 'helo', 'hellooo', 'hey', 'heyy', 'hola', 'kumusta', 'kamusta', 'musta', 'morning', 'afternoon', 'evening', 'umaga', 'hapon', 'gabi', 'greetings'];

    private const GREETING_FILL = ['po', 'poh', 'lumi', 'there', 'everyone', 'ate', 'kuya', 'sir', 'maam', 'mam', 'miss', 'good', 'magandang', 'gandang', 'day', 'araw', 'sa', 'inyo', 'inyong', 'lahat', 'jhunlea', 'ka', 'kayo', 'how', 'are', 'you', 'oh', 'uy', 'huy'];

    private const THANKS_CORE = ['thanks', 'thank', 'thankyou', 'ty', 'tysm', 'tnx', 'thx', 'salamat', 'bye', 'goodbye', 'paalam', 'ingat'];

    private const THANKS_FILL = ['you', 'so', 'much', 'very', 'maraming', 'ok', 'okay', 'sige', 'noted', 'a', 'lot', 'alot', 'again', 'ulit', 'see', 'later', 'ya', 'din', 'rin', 'kayo', 'too', 'great', 'nice', 'ha', 'na', 'for', 'the', 'help', 'sa', 'tulong', 'and', 'at'];

    private const HOURS_CORE = ['hours', 'hour', 'open', 'opening', 'oras', 'bukas', 'sarado', 'closed', 'close', 'closing', 'nagbubukas', 'nagsasara'];

    private const HOURS_FILL = ['what', 'whats', 'is', 'are', 'your', 'the', 'shop', 'studio', 'store', 'now', 'today', 'ngayon', 'until', 'til', 'till', 'time', 'do', 'you', 'anong', 'ano', 'ang', 'ba', 'kayo', 'niyo', 'nyo', 'ninyo', 'ng', 'inyong', 'inyo', 'kailan', 'when', 'still', 'pa', 'na', 'hanggang', 'mga', 'business', 'working', 'days', 'day', 'tuwing', 'araw', 'tomorrow', 'weekend', 'weekends', 'on', 'at', 'anong', 'oras', 'what\'s', 'kayong', 'po',
        'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'linggo', 'lunes', 'martes', 'miyerkules', 'huwebes', 'biyernes', 'sabado'];

    /**
     * Phase 7E-2b: a day or time period other than now. An hours question that names one ("open tomorrow?", "bukas
     * ba kayo sa Linggo?", "until 7pm?") goes to the model, which has the NOW line and the week's hours: the fixed
     * reply only says whether the shop is open NOW.
     */
    private const DAY_WORDS = ['tomorrow', 'tmrw', 'tmr', 'weekend', 'weekends', 'next', 'week', 'holiday', 'holidays', 'tonight', 'later', 'mamaya', 'mamayang',
        'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sun', 'mon', 'tue', 'tues', 'wed', 'thu', 'thurs', 'fri', 'sat',
        'linggo', 'lunes', 'martes', 'miyerkules', 'miyerkoles', 'huwebes', 'biyernes', 'sabado', 'susunod', 'pasko', 'christmas', 'undas'];

    private const CONTACT_ADDRESS = ['address', 'location', 'located', 'nasaan', 'saan', 'where', 'lugar'];

    private const CONTACT_PHONE = ['number', 'phone', 'cellphone', 'cp', 'mobile', 'landline', 'call', 'tawag', 'tumawag', 'tel', 'telephone', 'contact', 'reach', 'makontak', 'kontak'];

    private const CONTACT_EMAIL = ['email', 'mail', 'gmail'];

    private const CONTACT_FILL = ['what', 'whats', 'is', 'are', 'your', 'the', 'shop', 'studio', 'store', 'can', 'i', 'we', 'you', 'how', 'do', 'ba', 'ang', 'kayo', 'niyo', 'nyo', 'inyong', 'inyo', 'ninyo', 'ng', 'sa', 'details', 'detail', 'info', 'information', 'exact', 'give', 'me', 'please', 'pls', 'pa', 'paki', 'yung', 'ung', 'at', 'and', 'or', 'may', 'pwede', 'puwede', 'makuha', 'kayong', 'na', 'po', 'ano', 'anong'];

    // Words that only Filipino uses: one is enough to answer in Filipino
    private const FILIPINO = ['po', 'poh', 'ba', 'kayo', 'ang', 'saan', 'nasaan', 'salamat', 'magandang', 'gandang', 'kumusta', 'kamusta', 'musta', 'oras', 'bukas', 'ngayon', 'niyo', 'nyo', 'ninyo', 'paalam', 'ingat', 'sarado', 'anong', 'ano', 'maraming', 'umaga', 'hapon', 'gabi', 'inyong', 'inyo', 'ng', 'mga', 'sige', 'hanggang', 'kailan', 'tawag', 'tumawag', 'makontak', 'kontak', 'ulit', 'rin', 'din', 'pwede', 'puwede', 'paki', 'yung', 'ung', 'kayong', 'tulong', 'lugar', 'nagbubukas', 'nagsasara', 'tuwing', 'araw'];

    private const DAYS_EN = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    public function __construct(
        private readonly LiveFacts $live,
        private readonly ShopSettings $shop,
    ) {}

    /** The reply built by code, or null when the model should answer. */
    public function answer(string $message): ?string
    {
        if (! config('assistant.code_answers.enabled', true) || mb_strlen(trim($message)) > (int) config('assistant.code_answers.max_chars', 60)) {
            return null;
        }

        $words = $this->words($message);
        $intent = $words === [] ? null : $this->intent($words);

        if ($intent === null || ($intent === self::HOURS && $this->namesAnotherTime($message, $words))) {
            return null;
        }

        $filipino = array_intersect($words, self::FILIPINO) !== [];

        return match ($intent) {
            self::GREETING, self::THANKS => $this->fixed($intent, $filipino),
            self::HOURS => $this->hours($filipino),
            self::CONTACT => $this->contact($words, $filipino),
        };
    }

    /**
     * The one intent every word belongs to (greeting words may come with the others), or null.
     *
     * @param  list<string>  $words
     */
    public function intent(array $words): ?string
    {
        $greeting = [...self::GREETING_CORE, ...self::GREETING_FILL];
        $contactCore = [...self::CONTACT_ADDRESS, ...self::CONTACT_PHONE, ...self::CONTACT_EMAIL];

        $matches = array_keys(array_filter([
            self::THANKS => $this->fits($words, self::THANKS_CORE, [...self::THANKS_FILL, ...$greeting]),
            self::HOURS => $this->fits($words, self::HOURS_CORE, [...self::HOURS_FILL, ...$greeting]),
            self::CONTACT => $this->fits($words, $contactCore, [...self::CONTACT_FILL, ...$greeting]),
        ]));

        if (count($matches) === 1) {
            return $matches[0];
        }

        // Several possible intents is a second question: the model decides
        if ($matches !== []) {
            return null;
        }

        return $this->fits($words, self::GREETING_CORE, self::GREETING_FILL) ? self::GREETING : null;
    }

    /**
     * True when an hours question is about a day or time other than now: a day word, a clock time ("7pm", "5:30"), or
     * "bukas" twice ("bukas ba kayo bukas?": open tomorrow). "bukas" once stays an hours word ("open").
     *
     * @param  list<string>  $words
     */
    private function namesAnotherTime(string $message, array $words): bool
    {
        return array_intersect($words, self::DAY_WORDS) !== []
            || count(array_keys($words, 'bukas', true)) > 1
            || preg_match('/\b\d{1,2}(?::\d{2})?\s*(?:am|pm|a\.m\.|p\.m\.)|\b\d{1,2}:\d{2}\b/iu', $message) === 1;
    }

    /**
     * @param  list<string>  $words
     * @param  list<string>  $core
     * @param  list<string>  $fill
     */
    private function fits(array $words, array $core, array $fill): bool
    {
        return array_intersect($words, $core) !== [] && array_diff($words, $core, $fill) === [];
    }

    /** @return list<string> lower case words without punctuation or emoji; "good-bye" and "e-mail" join up */
    private function words(string $message): array
    {
        $text = mb_strtolower($message);
        $text = str_replace(['good-bye', 'e-mail', 'thank-you', 'what\'s', 'whats'], ['goodbye', 'email', 'thankyou', 'what', 'what'], $text);
        $text = preg_replace("/['’]/u", '', $text) ?? '';
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? '';

        return array_values(array_filter(explode(' ', trim($text)), fn (string $word) => $word !== ''));
    }

    private function fixed(string $intent, bool $filipino): string
    {
        return str_replace(':name', (string) config('assistant.name'), (string) config("assistant.code_answers.replies.{$intent}.".($filipino ? 'fil' : 'en')));
    }

    /** Open now (or when it next opens) and the week's hours, grouped; null when no day has hours. */
    private function hours(bool $filipino): ?string
    {
        $week = $this->week($filipino);

        if ($week === null) {
            return null;
        }

        return $filipino
            ? $this->live->openTextFilipino().' Ang oras namin (oras sa Pilipinas): '.$week.'.'
            : $this->live->openText().' Our opening hours (Philippine time): '.$week.'.';
    }

    /** "Monday to Saturday 9:00 AM to 5:00 PM; Sunday closed", Monday first. */
    private function week(bool $filipino): ?string
    {
        $rules = AvailabilityRule::week();
        $days = $filipino ? LiveFacts::FILIPINO_DAYS : self::DAYS_EN;
        $groups = [];
        $open = false;

        foreach ([1, 2, 3, 4, 5, 6, 0] as $weekday) {
            $rule = $rules[$weekday];
            $hours = $rule->is_open && $rule->opens_at && $rule->closes_at
                ? $this->time($rule->opens_at).($filipino ? ' hanggang ' : ' to ').$this->time($rule->closes_at)
                : ($filipino ? 'sarado' : 'closed');
            $open = $open || ($rule->is_open && $rule->opens_at && $rule->closes_at);
            $last = array_key_last($groups);

            if ($last !== null && $groups[$last]['hours'] === $hours) {
                $groups[$last]['to'] = $days[$weekday];
            } else {
                $groups[] = ['from' => $days[$weekday], 'to' => null, 'hours' => $hours];
            }
        }

        if (! $open) {
            return null;
        }

        return implode('; ', array_map(fn (array $group) => $group['from'].($group['to'] === null ? '' : ($filipino ? ' hanggang ' : ' to ').$group['to']).' '.$group['hours'], $groups));
    }

    /**
     * The asked-for contact facts, only those that are set; null when one that was asked for is missing.
     *
     * @param  list<string>  $words
     */
    private function contact(array $words, bool $filipino): ?string
    {
        $address = array_intersect($words, self::CONTACT_ADDRESS) !== [];
        $email = array_intersect($words, self::CONTACT_EMAIL) !== [];
        $phone = array_intersect($words, self::CONTACT_PHONE) !== [];
        $generic = array_intersect($words, ['contact', 'reach', 'makontak', 'kontak', 'details', 'info', 'information']) !== [];

        $facts = [];

        if ($address) {
            if ($this->shop->address() === null) {
                return null;
            }

            $facts[] = 'Address: '.$this->shop->address();
        }

        if ($phone && ! $generic && $this->shop->phone() === null) {
            return null;
        }

        if ($email && ! $generic && $this->shop->email() === null) {
            return null;
        }

        if (($phone || $generic) && $this->shop->phone() !== null) {
            $facts[] = 'Phone: '.$this->shop->phone();
        }

        if (($email || $generic) && $this->shop->email() !== null) {
            $facts[] = 'Email: '.$this->shop->email();
        }

        if ($facts === []) {
            return null;
        }

        $name = $this->shop->shopName() ?? (string) config('app.name');

        return $filipino
            ? "Ito po ang detalye ng {$name}: ".implode('. ', $facts).'. Pwede rin kayong mag-message sa studio sa Studio tab.'
            : "Here are the details of {$name}: ".implode('. ', $facts).'. You can also message the studio in the Studio tab.';
    }

    private function time(string $value): string
    {
        return Carbon::createFromFormat('H:i', substr($value, 0, 5))->format('g:i A');
    }
}
