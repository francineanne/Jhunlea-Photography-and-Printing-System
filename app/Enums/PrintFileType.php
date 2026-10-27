<?php

namespace App\Enums;

/** File types a customer may upload for a printing service (extensions; Phase 3 also checks MIME and size on upload). */
enum PrintFileType: string
{
    case Pdf = 'pdf';
    case Jpg = 'jpg';
    case Png = 'png';
    case Docx = 'docx';
    case Pptx = 'pptx';
    case Xlsx = 'xlsx';

    public function label(): string
    {
        return strtoupper($this->value);
    }
}
