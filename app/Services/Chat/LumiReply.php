<?php

namespace App\Services\Chat;

use App\Models\ChatMessage;

/** What LumiReplyService did. $messages are the rows it stored now, or the reply stored earlier when already answered. */
final class LumiReply
{
    public const REPLIED = 'replied';

    public const ALREADY_ANSWERED = 'already_answered';

    // Nothing to answer: the latest message is not the customer's
    public const NOTHING_TO_DO = 'nothing_to_do';

    // The studio thread while the owner is present or the thread waits for the owner: Lumi stays quiet
    public const NOT_ALLOWED = 'not_allowed';

    // The situation changed while the model was answering (the owner replied, another reply was stored): nothing stored
    public const DISCARDED = 'discarded';

    // Another request for this conversation is waiting for the model right now: ask again shortly
    public const IN_PROGRESS = 'in_progress';

    /** @param list<ChatMessage> $messages */
    public function __construct(
        public readonly string $status,
        public readonly array $messages = [],
    ) {}
}
