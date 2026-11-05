<?php

namespace App\Services\Assistant\Eval;

use App\Services\Assistant\ResponseValidator;

/**
 * The real ResponseValidator, bound in the eval process only (Phase 7E-2e), that also keeps the RAW reply text the model
 * sent, before PlainText touched it, so EvalCharacters can show which characters the model really wrote. Nothing is
 * changed in the reply, and the raw text is kept in memory only: never in the database, never in a report.
 */
class RecordingResponseValidator extends ResponseValidator
{
    public ?string $raw = null;

    public function plainText(string $text): string
    {
        $this->raw = $text;

        return parent::plainText($text);
    }
}
