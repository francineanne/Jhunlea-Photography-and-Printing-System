<?php

namespace App\Enums;

/** What the model says its reply is. Anything outside this list is a bad response. */
enum AssistantIntent: string
{
    case Answer = 'answer';
    case Recommend = 'recommend';
    case Clarify = 'clarify';
    case Handoff = 'handoff';
    case OffTopic = 'off_topic';
}
