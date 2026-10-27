<?php

namespace App\Enums;

enum PricingType: string
{
    case PerPage = 'per_page';
    case PerCopy = 'per_copy';
    case Quotation = 'quotation';

    public function label(): string
    {
        return match ($this) {
            self::PerPage => 'Per page',
            self::PerCopy => 'Per copy',
            self::Quotation => 'Quotation',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::PerPage => 'The base price is charged for each page.',
            self::PerCopy => 'The base price is charged for each copy or piece.',
            self::Quotation => 'The owner gives a price after reviewing the request. A base price is optional.',
        };
    }
}
