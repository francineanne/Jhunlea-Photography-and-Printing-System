<?php

namespace App\Enums;

/** Who wrote a chat message. "assistant" is Lumi (always shown as AI); "system" is a fixed notice from the app. */
enum MessageSender: string
{
    case Customer = 'customer';
    case Owner = 'owner';
    case Assistant = 'assistant';
    case System = 'system';

    /** A reply that ends the customer's streak of unanswered messages. */
    public function answersCustomer(): bool
    {
        return $this === self::Owner || $this === self::Assistant;
    }
}
