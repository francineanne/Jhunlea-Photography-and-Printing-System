<?php

namespace App\Services\Assistant;

use App\Enums\AssistantCardType;
use App\Enums\AssistantIntent;

/** The model's answer after ResponseValidator: a known intent, plain-text reply and well-formed recommendations. */
final class ParsedReply
{
    /** @param list<array{type: AssistantCardType, id: int}> $recommend */
    public function __construct(
        public readonly AssistantIntent $intent,
        public readonly string $reply,
        public readonly array $recommend,
    ) {}
}
