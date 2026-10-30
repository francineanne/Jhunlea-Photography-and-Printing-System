<?php

namespace App\Enums;

/** How one assistant call ended. Stored in assistant_runs.outcome. */
enum AssistantOutcome: string
{
    case Answered = 'answered';
    case Refused = 'refused';
    case Handoff = 'handoff';
    case RejectedInput = 'rejected_input';
    case FallbackUnavailable = 'fallback_unavailable';
    case FallbackLimitUser = 'fallback_limit_user';
    case FallbackLimitGlobal = 'fallback_limit_global';
    case FallbackDisabled = 'fallback_disabled';
    case AwayCapReached = 'away_cap_reached';
    case FallbackError = 'fallback_error';
}
