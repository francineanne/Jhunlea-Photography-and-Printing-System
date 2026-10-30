<?php

namespace App\Services\Assistant;

use App\Enums\AssistantFeature;

/**
 * One call to the engine. $message is the latest customer message (the one to answer, or for owner_assist the newest
 * message of the thread); $history holds the earlier messages of the same conversation, oldest first. For
 * studio_away, $consecutiveAiReplies is how many of the latest thread messages were AI replies with no owner reply
 * in between.
 */
final class AssistantInput
{
    /** @param list<ThreadMessage> $history */
    public function __construct(
        public readonly AssistantFeature $feature,
        public readonly AssistantActor $actor,
        public readonly string $message,
        public readonly array $history = [],
        public readonly int $consecutiveAiReplies = 0,
    ) {}
}
