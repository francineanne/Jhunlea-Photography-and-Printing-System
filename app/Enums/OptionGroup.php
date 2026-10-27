<?php

namespace App\Enums;

enum OptionGroup: string
{
    case PaperSize = 'paper_size';
    case PaperType = 'paper_type';
    case ColorMode = 'color_mode';
    case Sides = 'sides';
    case PrintSize = 'print_size';
    case Finish = 'finish';

    public function label(): string
    {
        return match ($this) {
            self::PaperSize => 'Paper size',
            self::PaperType => 'Paper type',
            self::ColorMode => 'Color mode',
            self::Sides => 'Sides',
            self::PrintSize => 'Print size',
            self::Finish => 'Finish',
        };
    }
}
