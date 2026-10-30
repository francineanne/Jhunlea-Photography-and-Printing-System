<?php

namespace App\Services\Assistant;

use App\Enums\AssistantAuthor;

/** One earlier message of the conversation, passed in by the caller. */
final class ThreadMessage
{
    public function __construct(
        public readonly AssistantAuthor $author,
        public readonly string $text,
    ) {}
}
