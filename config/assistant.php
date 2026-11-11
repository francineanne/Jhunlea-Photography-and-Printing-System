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
            // Phase 7E-3a: owner_ask added; the shares still add up to 1.0 (were 0.50, 0.35, 0.15)
            'ask_lumi' => 0.45,
            'studio_away' => 0.30,
            'owner_assist' => 0.10,
            'owner_ask' => 0.15,
        ],

        // Phase 7E-3a: a feature with limits of its own counts only its own calls against them (and its own minute
        // counter); every other feature keeps the per-user limits above
        'features' => [
            // 7E-3b: 20 a minute (was 10)
            'owner_ask' => ['per_minute' => 20, 'per_day' => 200],
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
            // Gemini (7E-2c): Google's official pricing page (ai.google.dev/gemini-api/docs/pricing, which now redirects to
            // aistudio.google.com/docs/pricing), read 2026-11-05: Standard, paid tier, text input; output includes thinking
            // tokens; cache_read is the context caching price (its per-hour storage price is not counted here)
            'gemini-3.1-flash-lite' => ['input' => 0.25, 'output' => 1.50, 'cache_read' => 0.025],
            'gemini-3.5-flash-lite' => ['input' => 0.30, 'output' => 2.50, 'cache_read' => 0.03],
        ],
    ],

    // Phase 7E-2b: `php artisan assistant:eval`. Its dry run (a live run without --yes) multiplies the model calls by
    // these tokens per call, measured on the 7E-2a live QA (about 2,000 uncached input, 2,700 read from the cache and
    // 250 output tokens per Claude answer). An estimate only
    'eval' => [
        // The eval's own throwaway SQLite file; it must stay inside storage/app/evals (null: storage/app/evals/eval.sqlite)
        'database' => null,
        'estimate_tokens_per_call' => ['input' => 2100, 'output' => 260, 'cache_read' => 2700, 'cache_write' => 0],
        // Words with a lower-upper case change that no_glued_words accepts (7E-2e); compared ignoring case
        'camel_case_allowed' => ['GCash', 'PayMaya', 'iPhone', 'iPad', 'iMac', 'McDonald', "McDonald's", 'PriceGuard', 'ClaimGuard',
            'YouTube', 'TikTok', 'WhatsApp', 'LinkedIn', 'PayPal', 'JPEGs', 'PDFs', 'PNGs', 'IDs', 'URLs', 'FAQs', 'SMSes', 'eBook', 'eVoucher'],
    ],

    // Phase 7E-3a: the owner's Ask Lumi (OwnerAskService). Read-only tools, at most max_tools per question and two
    // provider calls (plan, then phrase); the owner's own conversation keeps the last history_messages
    'owner' => [
        'max_tools' => 3,
        // 7E-3b: the chips of the owner's panel and page
        'glance_prompt' => 'What should I do first today?',
        'suggested_prompts' => ['How much did we make today?', 'Who still has to pay?', 'What shoots do we have tomorrow?', 'What should I do today?', 'Do I have messages waiting?'],
        'history_messages' => 6,
        // The most characters of tool data sent to the phrasing call
        'data_max_chars' => 6000,
        'how_to' => [
            // Phase 8D: the counter on the Payments page (8B) is the usual place to record a payment
            'record_payment' => ['page' => 'Payments and counter', 'route' => 'owner.payments.index', 'steps' => [
                'Open Payments and press Record payment.',
                'Find the order or booking by customer name, phone, or the PR- or PB- number, and choose it. It needs a confirmed price; the amount to collect is always the full confirmed price (no partial payment or downpayment).',
                'Choose Cash or GCash. For GCash type the GCash reference number. For cash you may type the cash received: it only works out the change and is not saved.',
                'Tick "I collected the full amount", press Continue, check the details and press Record payment.',
                'You get the PAY number; press Print receipt if the customer wants one. You can also record it from the Payment card of the order or booking.']],
            'payment_receipt' => ['page' => 'Payments and counter', 'route' => 'owner.payments.index', 'steps' => [
                'After recording a payment press Print receipt, or press Print receipt on its row in the Payments ledger or on the order or booking.',
                'It prints on a normal printer and can be printed again any time. A voided payment prints with a VOID mark.',
                'It is a payment receipt from the system, not an official receipt.']],
            'walk_in_order' => ['page' => 'Payments and counter', 'route' => 'owner.orders.walk-in.create', 'steps' => [
                'On Payments press New walk-in, then Walk-in printing order.',
                'Find the customer by name or phone, or add a new walk-in customer (a name and an optional phone; they get no account).',
                'Choose the printing service, options and copies; the price comes from the catalog, or type the price and why it differs.',
                'A walk-in printing order is paid when it is made: choose Cash or GCash and press Save order and payment. Printing starts right away.']],
            'walk_in_booking' => ['page' => 'Payments and counter', 'route' => 'owner.bookings.walk-in.create', 'steps' => [
                'On Payments press New walk-in, then Walk-in booking.',
                'Find or add the customer and type their contact number, choose the package and a free time, and press Save confirmed booking.',
                'The booking is confirmed right away and is paid on the day of the shoot with Record payment.']],
            'void_payment' => ['page' => 'Payments', 'route' => 'owner.payments.index', 'steps' => [
                'Open the order or booking that holds the payment.',
                'In the Payment card press Void this payment record and write the reason.',
                'A voided payment stays in the ledger but never counts as income. It cannot be voided after Completed. There is no refund workflow.']],
            'income_report' => ['page' => 'Reports', 'route' => 'owner.reports.income', 'steps' => [
                'Open Reports. The Income tab shows the income of the period you choose; the Requests tab shows requests per status.',
                'Choose Daily, Weekly (Monday to Sunday), Monthly, Custom or All time, then use the arrows, the presets or the date picker.',
                'Your last choice of Daily, Weekly or Monthly is remembered for the next time.',
                'Income is the recorded payments only; voided payments and prices still awaiting payment are never counted.']],
            'end_of_day' => ['page' => 'Reports', 'route' => 'owner.reports.end-of-day', 'steps' => [
                'Open Reports, choose Daily and the day, then press End-of-day summary.',
                'It lists the day\'s payments, the voided ones apart, Cash and GCash, and what is still awaiting payment. Press Print summary.',
                'The system does not count the cash drawer or close the day; the Cash total is what should be in the drawer.']],
            'export_csv' => ['page' => 'Reports', 'route' => 'owner.reports.income', 'steps' => [
                'Open Reports and choose the period, then press Export CSV.',
                'The file lists every payment of the period (voided ones marked Voided) and opens in Excel with the peso sign.']],
            'print_report' => ['page' => 'Reports', 'route' => 'owner.reports.income', 'steps' => [
                'Open Reports, choose the period and press Print report. Only the report prints, in black on white.']],
            'awaiting_payment' => ['page' => 'Payments and counter', 'route' => 'owner.payments.index', 'steps' => [
                'Awaiting payment means an order or booking has a confirmed price but no recorded payment yet. It is never income.',
                'The Awaiting payment tab on Payments lists them, the longest waiting first; press Record payment on one to collect it.']],
            'official_receipt' => ['page' => 'Payments and counter', 'route' => 'owner.payments.index', 'steps' => [
                'The system only records payments; its payment receipt says "This is not an official receipt."',
                'For official receipts, invoices or taxes, please ask your accountant or the BIR. Lumi cannot give tax advice.']],
            'confirm_booking' => ['page' => 'Bookings', 'route' => 'owner.bookings.index', 'steps' => [
                'Open the booking request.',
                'Check the requested time on the schedule, then set the confirmed price.',
                'Press Confirm, or Propose another time if it does not fit.']],
            'accept_order' => ['page' => 'Printing orders', 'route' => 'owner.orders.index', 'steps' => [
                'Open the order and check the files.',
                'Set the confirmed price and press Accept, or Ask for information, or Reject with a reason.']],
            'change_hours' => ['page' => 'Schedule editor', 'route' => 'owner.schedule.edit', 'steps' => [
                'Open Schedule, then Edit hours and rules.',
                'Change the opening hours of a weekday, or add a blocked date.',
                'Save. Confirmed bookings are never moved; any left outside the new hours are listed for you.']],
            'block_date' => ['page' => 'Schedule editor', 'route' => 'owner.schedule.edit', 'steps' => [
                'Open Schedule, then Edit hours and rules.',
                'Under Blocked dates pick the day, write a reason and add it.']],
            'edit_package' => ['page' => 'Packages', 'route' => 'owner.packages.index', 'steps' => [
                'Open Packages and press Edit on the package.',
                'Change the price, inclusions or photos and save. Published packages show on the website.']],
            'edit_service' => ['page' => 'Services', 'route' => 'owner.services.index', 'steps' => [
                'Open Services and press Edit on the printing service.',
                'Change the price, options or accepted files and save.']],
            'edit_faq' => ['page' => 'FAQ', 'route' => 'owner.faqs.index', 'steps' => [
                'Open FAQ and add or edit a question.',
                'Published questions show on the public FAQ page and Lumi reads them.']],
            'reports' => ['page' => 'Reports', 'route' => 'owner.reports.index', 'steps' => [
                'Open Reports. The Income tab shows the money received; the Requests tab shows requests per status and the most requested items.',
                'Pick a period on either tab.']],
            'reply_messages' => ['page' => 'Messages', 'route' => 'owner.messages.index', 'steps' => [
                'Open Messages and choose Unanswered.',
                'Open a thread and reply; Suggest reply writes a draft you can edit.']],
            'settings' => ['page' => 'Settings', 'route' => 'owner.settings.index', 'steps' => [
                'Open Settings to change the business info and the upload limits.']],
        ],
    ],

    // The fixed texts. Customers always get one of these instead of an error page
    'messages' => [
        'off_topic' => "I'm here to help with Jhunlea's photography and printing services. I can help you explore packages, printing options, booking requirements, and request updates. What service are you interested in?",
        'unavailable' => 'Lumi is taking a short break right now. You can browse the FAQ or message the studio.',
        'limit_user' => 'You have reached today\'s limit for Lumi. It resets at midnight. You can still message the studio.',
        // 7E-3b: the per-minute limit says so, instead of the daily text
        'limit_minute' => 'You are asking quickly. Please wait a minute and try again.',
        'studio_away' => 'The studio received your message and will reply when we open.',
        'owner_assist' => 'The suggestion is not available right now. You can still reply manually.',
        // Phase 7E-3a: the owner's Ask Lumi
        'owner_ask' => 'Lumi cannot answer right now. The Overview, Payments and Reports pages have the same numbers.',
        'owner_ask_limit_minute' => 'You are asking quickly. Please wait a minute and try again.',
        'owner_ask_limit' => 'You have reached today\'s limit for Ask Lumi. It resets at midnight. The Reports page has the same numbers.',
        'owner_ask_unverified' => 'I could not check those numbers against your records, so I left them out. Please see the Reports page for the exact figures.',
        'owner_ask_clarify' => 'Could you say a bit more? For example which period (today, this week, last month) or which customer, order or booking you mean.',
        // 7E-3b: a polite refusal when an answer was blocked for naming the prompt's own sections
        'owner_ask_cannot_show' => 'I can\'t show that here. You can see it on the page linked below.',
        // 7E-3b: the owner's fixed texts for a Filipino or Taglish question (OwnerAskService::text())
        'owner_ask_fil' => 'Hindi makasagot si Lumi ngayon. Nasa Overview, Payments at Reports pages ang parehong numero.',
        'owner_ask_limit_minute_fil' => 'Mabilis po ang mga tanong. Maghintay ng isang minuto at subukan ulit.',
        'owner_ask_limit_fil' => 'Naabot na ang limit ng Ask Lumi para ngayong araw. Magre-reset ito sa hatinggabi. Nasa Reports page ang parehong numero.',
        'owner_ask_unverified_fil' => 'Hindi ko ma-check ang mga numerong iyon sa records ninyo kaya hindi ko isinama. Tingnan po ang Reports page para sa eksaktong numero.',
        'owner_ask_clarify_fil' => 'Pakidagdagan po ng kaunti. Halimbawa, anong panahon (ngayon, ngayong linggo, noong isang buwan) o aling customer, order o booking.',
        'owner_ask_out_of_scope_fil' => 'Mga tanong tungkol sa shop ninyo lang ang kaya kong sagutin: kita at reports, ang counter, hindi pa bayad, walk-in, orders, bookings, schedule, customers, messages at paggamit ng Lumi. Ano po ang gusto ninyong malaman?',
        'owner_ask_write_fil' => 'Nakakabasa lang ako ng records ninyo; hindi ako makakapagbago ng kahit ano. Magagawa ninyo ito sa page na naka-link sa ibaba.',
        'owner_ask_cannot_show_fil' => 'Hindi ko maipapakita iyon dito. Makikita ninyo ito sa page na naka-link sa ibaba.',
        'owner_ask_out_of_scope' => 'I can answer questions about your shop: income and the reports, the counter, unpaid balances, walk-ins, orders, bookings, the schedule, customers, messages and Lumi usage. What would you like to know?',
        'owner_ask_write' => 'I can only read your records; I cannot change anything. You can do it on the page linked below.',
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
