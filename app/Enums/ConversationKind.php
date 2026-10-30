<?php

namespace App\Enums;

/** Ask Lumi (customer and the AI only, private to the customer) or the one studio thread (customer and owner). */
enum ConversationKind: string
{
    case Assistant = 'assistant';
    case Studio = 'studio';

    /** conversations.thread_key: set only on the studio thread, so the unique index allows one per customer. */
    public function threadKey(): ?string
    {
        return $this === self::Studio ? 'studio' : null;
    }
}
