<?php

/*
| The AI customer assistant ("Lumi", Phase 7). The key lives only in .env (AI_API_KEY) and is read here, never with
| env() anywhere else, so config:cache works. An empty key or an unknown provider never breaks a page: the engine
| answers with the "unavailable" fallback instead.
*/

return [

    // gemini (the real one) or fake (scripted replies for tests and local development)
    // ?: so an empty line in .env (AI_MODEL=) still uses the default
    'provider' => env('AI_PROVIDER') ?: 'gemini',

    'api_key' => env('AI_API_KEY') ?: null,

    'model' => env('AI_MODEL') ?: 'gemini-3.5-flash-lite',

    'name' => 'Lumi',

    // Off switch for the whole assistant: every call answers with the fallback and the provider is never called
    'enabled' => env('AI_ENABLED') === null || env('AI_ENABLED') === '' ? true : (bool) env('AI_ENABLED'),

    'timeout_seconds' => 12,

    // The longest customer message accepted, checked before anything else
    'max_message_chars' => 1000,

    // How many earlier messages of a conversation are sent with the new one
    'history_messages' => 8,

    // The most characters of shop knowledge put in one prompt (about 3K tokens)
    'context_max_chars' => 12000,

    // The longest reply kept from the model, after it is turned into plain text
    'max_reply_chars' => 1200,

    // The most recommendation cards one reply may show
    'max_cards' => 3,

    'max_output_tokens' => 500,

    'temperature' => 0.2,

    'limits' => [
        'per_minute_per_user' => 6,
        'per_day_per_user' => 40,
        'per_day_per_guest_ip' => 10,

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

    // The fixed texts. Customers always get one of these instead of an error page
    'messages' => [
        'off_topic' => "I'm here to help with Jhunlea's photography and printing services. I can help you explore packages, printing options, booking requirements, and request updates. What service are you interested in?",
        'unavailable' => 'Pahinga muna si Lumi ngayon. You can browse the FAQ or message the studio.',
        'limit_user' => 'You have reached today\'s limit for Lumi. It resets at midnight. You can still message the studio.',
        'studio_away' => 'The studio received your message and will reply when we open.',
        'owner_assist' => 'The suggestion is not available right now. You can still reply manually.',
        'away_cap' => 'Lumi has replied several times in a row. The studio will reply to you directly when we open.',
        'empty' => 'Please type a message first.',
        'too_long' => 'Your message is too long. Please keep it under :max characters.',
    ],

];
