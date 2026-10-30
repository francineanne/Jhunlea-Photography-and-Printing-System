<?php

/*
| Messaging (Phase 7B): the customer's Ask Lumi conversations and their one studio conversation with the owner. Only
| anti-spam limits apply to people; the 20 messages a minute per user is the named throttle on the send route.
*/

return [

    'message_max_chars' => 1000,

    // Messages per page of a thread (the first page, "load earlier" and each poll)
    'page_size' => 50,

    // Conversations per page of the owner's inbox and the customer's Ask Lumi list
    'list_page_size' => 20,

    // Customer messages one person may send in one Manila day, all conversations together
    'per_day_customer_messages' => 500,

    // Customer messages in a row in the studio thread with no reply from the owner or Lumi; the next one is refused
    'max_unanswered_customer_messages' => 20,

    // Every message of one Ask Lumi conversation together (customer, Lumi and notices); then a new one is started
    'assistant_conversation_max_messages' => 60,

    'presence' => [
        // In "auto" the owner counts as away after this long without opening or polling the inbox or replying
        'active_minutes' => 15,
        // owner_last_active_at is written at most this often
        'touch_interval_seconds' => 30,
    ],

    // How often the client should poll, in seconds: while the thread is open and in use, idle, and long idle
    'poll' => [
        'open' => 5,
        'idle' => 30,
        'long_idle' => 60,
    ],

    // Characters of the last message kept for the inbox and conversation lists
    'preview_chars' => 120,

    'messages' => [
        'away_notice' => 'The studio is away right now and will be back :opens. Lumi, our AI assistant, can help you in the meantime. Babalikan ka namin agad.',
        'away_notice_no_time' => 'The studio is away right now. Lumi, our AI assistant, can help you in the meantime. Babalikan ka namin agad.',
        'lumi_intro' => 'Hi! I\'m Lumi, the AI assistant of Jhunlea Photography & Printing. I can help you explore our photography packages, printing services, booking rules and policies. Ano ang maitutulong ko?',
        'handoff_to_owner' => 'The studio is now with you.',
        'waiting_for_studio' => 'You have sent several messages in a row. Please wait for the studio to reply before sending more. Salamat sa pasensya!',
        'conversation_full' => 'This conversation is full. Please start a new conversation with Lumi.',
        'owner_unavailable_ai_off' => 'The studio is away right now and will reply to your message when we are back.',
        'daily_cap' => 'You have reached today\'s message limit. You can send more messages tomorrow.',
        'invalid_message' => 'Please type a message of up to :max characters.',
        'invalid_about' => 'That order or booking could not be found in your account.',
        'invalid_from_assistant' => 'That Ask Lumi conversation could not be found in your account.',
        'rate_limited' => 'You are sending messages too quickly. Please wait a moment.',
        'not_found' => 'This conversation could not be found.',
        'forbidden' => 'You cannot do that here.',
    ],

];
