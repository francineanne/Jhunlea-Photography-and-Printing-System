<?php

namespace App\Enums;

/** What a fixed notice in a conversation is, stored in chat_messages.meta.system_kind. */
enum SystemMessageKind: string
{
    case LumiIntro = 'lumi_intro';
    case AwayNotice = 'away_notice';
    case OwnerJoined = 'owner_joined';
    case AiUnavailable = 'ai_unavailable';
    case AwayCap = 'away_cap';

    /** Notices that belong to a stretch in which Lumi answers for the away owner. */
    public function partOfLumiStretch(): bool
    {
        return in_array($this, [self::AwayNotice, self::AiUnavailable, self::AwayCap], true);
    }
}
