<?php

namespace App\Enums;

/** Who wrote one message of a conversation the assistant reads. */
enum AssistantAuthor: string
{
    case Customer = 'customer';
    case Lumi = 'lumi';
    case Owner = 'owner';

    /** How the message is labelled in a thread the owner asks to be summarized or answered. */
    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Customer',
            self::Lumi => 'Lumi (AI)',
            self::Owner => 'Studio',
        };
    }
}
