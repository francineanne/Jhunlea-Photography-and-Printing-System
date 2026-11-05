<?php

/*
| The AI customer assistant ("Lumi", Phase 7). The keys live only in .env (AI_ANTHROPIC_API_KEY, AI_API_KEY) and are
| read here, never with env() anywhere else, so config:cache works. An empty key or an unknown provider never breaks a
| page: the engine tries the backup provider, then answers with the "unavailable" fallback.
*/

return [

    // The provider that answers first (Phase 7E-1): anthropic (Claude), gemini, or fake (scripted replies for tests
    // and local development). ?: so an empty line in .env (AI_PROVIDER=) still uses the default
    'provider' => env('AI_PROVIDER') ?: 'anthropic',

    // The backup, asked only when the first provider is rate limiting, down, has no usable key or sent a broken body.
    // Not set at all: gemini. Set to an empty value or "none": no backup
    'fallback_provider' => env('AI_FALLBACK_PROVIDER') === null ? 'gemini' : (in_array(strtolower(trim((string) env('AI_FALLBACK_PROVIDER'))), ['', 'none'], true) ? null : trim((string) env('AI_FALLBACK_PROVIDER'))),

    // Claude through the Messages API. No temperature is sent: Claude Haiku 5.5 refuses any non-default value
    'anthropic' => [
        // AI_ANTHROPIC_* only (Phase 7E-2a): the plain ANTHROPIC_API_KEY is also read by other tools and may sit in the
        // process environment, where it would shadow .env (Laravel never overrides an existing variable). It is ignored
        'api_key' => env('AI_ANTHROPIC_API_KEY') ?: null,
        'model' => env('AI_ANTHROPIC_MODEL') ?: 'claude-haiku-5-5',
        'version' => '2023-06-01',
        // Phase 7E-2a: the stable part of the system text (rules, fixed knowledge, catalog index) is marked for prompt
        // caching. Claude Haiku 5.5 caches a prefix of 512 tokens or more (the prompt caching docs, checked Nov 2026);
        // a shorter one is simply not cached, without an error
        'prompt_cache' => true,
    ],

    // Gemini (the backup): its key and model keep their Phase 7A names
    'api_key' => env('AI_API_KEY') ?: null,

    'model' => env('AI_MODEL') ?: 'gemini-3.5-flash-lite',

    'name' => 'Lumi',

    // Off switch for the whole assistant: every call answers with the fallback and the provider is never called
    'enabled' => env('AI_ENABLED') === null || env('AI_ENABLED') === '' ? true : (bool) env('AI_ENABLED'),

    // Per provider call. With a backup, the worst case is two timeouts in a row (about 24 seconds)
    'timeout_seconds' => 12,

    // The longest customer message accepted, checked before anything else
    'max_message_chars' => 1000,

    // How many earlier messages of a conversation are sent with the new one (8 until Phase 7E-2a)
    'history_messages' => 6,

    // How many messages of a studio thread the owner's Suggest reply and Summarize read
    'owner_assist_messages' => 20,

    // The most characters of the STABLE shop knowledge (business info, hours, rules, where things are, catalog index)
    'context_max_chars' => 12000,

    // Phase 7E-2a: only the item and FAQ details that match the message are sent, at most this many and this many
    // characters together (before: every item, up to context_max_chars)
    'detail_items' => 4,
    'details_max_chars' => 2400,

    // Phase 7E-2a: Taglish and everyday words mapped to the catalog's own words, for picking the details to send.
    // Only the ranking uses them; the model still reads the customer's own words
    'synonyms' => [
        'kasal' => 'wedding',
        'ikakasal' => 'wedding',
        'binyag' => 'christening baptism',
        'bunyag' => 'christening baptism',
        'grad pics' => 'graduation',
        'grad pic' => 'graduation',
        'grad' => 'graduation',
        'toga' => 'graduation',
        'id pic' => 'id photo',
        'id picture' => 'id photo',
        '2x2' => 'id photo passport',
        '1x1' => 'id photo',
        'passport' => 'passport photo',
        'kuha' => 'photo',
        'litrato' => 'photo',
        'larawan' => 'photo',
        'picture' => 'photo',
        'pic' => 'photo',
        'pa-print' => 'printing',
        'pa print' => 'printing',
        'paprint' => 'printing',
        'magpa-print' => 'printing',
        'imprenta' => 'printing',
        'family pic' => 'family portrait',
        'pamilya' => 'family',
        'mag-asawa' => 'couple',
        'magjowa' => 'couple',
        'jowa' => 'couple',
        'bata' => 'kids',
        'anak' => 'kids',
        'kaarawan' => 'birthday',
        'bday' => 'birthday',
        'debut' => 'birthday debut',
        'tarp' => 'tarpaulin',
        'laminate' => 'lamination',
        'nakalaminate' => 'lamination',
    ],

    // The longest reply kept from the model, after it is turned into plain text
    'max_reply_chars' => 1200,

    // The most recommendation cards one reply may show
    'max_cards' => 3,

    'max_output_tokens' => 500,

    // Gemini only (Claude gets none, see above)
    'temperature' => 0.2,

    // The most characters of the customer's own records put in one prompt (about 600 tokens); the oldest finished
    // record is dropped first
    'customer_context_max_chars' => 2400,

    'limits' => [
        'per_minute_per_user' => 6,
        // Raised in Phase 7E-2a (were 40 and 10). Answers by code (CodeAnswers) never count toward any of these
        'per_day_per_user' => 80,
        'per_day_per_guest_ip' => 20,

        // Every provider call of the shop together in one Manila day, split between the features so one cannot use
        // up what the others need
        'global_daily_budget' => 500,
        'global_shares' => [
            'ask_lumi' => 0.50,
            'studio_away' => 0.35,
            'owner_assist' => 0.15,
        ],

        // studio_away stops after this many AI replies in a row with no owner reply in between
        'studio_away_max_consecutive' => 5,

        // A 429 from the provider pauses every call for its retry-after, never longer than this
        'provider_cooldown_max_seconds' => 300,
    ],

    // Phase 7E-2a: short, clear messages (a greeting, thanks, the hours, "are you open now", contact details) answered by
    // code from the database without calling a provider. Anything else, or a needed fact that is not set, goes to the
    // model. :name is the assistant's name
    'code_answers' => [
        'enabled' => true,
        'max_chars' => 60,
        'replies' => [
            'greeting' => [
                'en' => "Hi! I'm :name, the AI assistant of Jhunlea Photography & Printing. I can help with our photography packages, printing services, booking rules and your own requests. What would you like to know?",
                'fil' => 'Hello po! Ako si :name, ang AI assistant ng Jhunlea Photography & Printing. Matutulungan kita sa photography packages, printing services, booking at sa mga request mo. Ano ang maitutulong ko?',
            ],
            'thanks' => [
                'en' => "You're welcome! Message me anytime if you have more questions.",
                'fil' => 'Walang anuman po! Mag-message lang kayo ulit kung may tanong pa.',
            ],
        ],
    ],

    // Phase 7E-2a: for `php artisan assistant:usage` only, an ESTIMATE. USD per 1M tokens per model. Check the providers'
    // pricing pages before relying on it. Claude Haiku 5.5 from Anthropic's model docs (Nov 2026, prompts up to 100K
    // tokens; cache read 0.1x, 5-minute cache write 1.25x of input). A model without a row shows no cost
    'pricing' => [
        'usd_to_php' => 58.0,
        'models' => [
            'claude-haiku-5-5' => ['input' => 0.10, 'output' => 0.50, 'cache_read' => 0.01, 'cache_write' => 0.125],
        ],
    ],

    // The fixed texts. Customers always get one of these instead of an error page
    'messages' => [
        'off_topic' => "I'm here to help with Jhunlea's photography and printing services. I can help you explore packages, printing options, booking requirements, and request updates. What service are you interested in?",
        'unavailable' => 'Lumi is taking a short break right now. You can browse the FAQ or message the studio.',
        'limit_user' => 'You have reached today\'s limit for Lumi. It resets at midnight. You can still message the studio.',
        'studio_away' => 'The studio received your message and will reply when we open.',
        'owner_assist' => 'The suggestion is not available right now. You can still reply manually.',
        // Used when ClaimGuard removed every sentence of a reply because each claimed an action or a status the
        // customer's records do not show
        'claim_unverified' => 'I can\'t confirm that from here. Please check My orders or My bookings for the latest status, or message the studio.',
        // Used when PriceGuard removed every sentence of a reply because each named a price the shop never gave
        'price_unverified' => 'Pasensya na po, hindi ako sigurado sa eksaktong presyo. Please message the studio for the exact price.',
        'away_cap' => 'Lumi has replied several times in a row. The studio will reply to you directly when we open.',
        'empty' => 'Please type a message first.',
        'too_long' => 'Your message is too long. Please keep it under :max characters.',
    ],

];
