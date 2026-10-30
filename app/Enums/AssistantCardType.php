<?php

namespace App\Enums;

/** The catalog records the assistant may recommend. Cards are always built from the database, never by the model. */
enum AssistantCardType: string
{
    case Service = 'service';
    case Package = 'package';
    case Theme = 'theme';
}
