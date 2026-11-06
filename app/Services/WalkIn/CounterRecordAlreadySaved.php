<?php

namespace App\Services\WalkIn;

use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use RuntimeException;

/**
 * Thrown inside the counter transaction when the form's token already made its record (a double click or a retry), so
 * the transaction rolls back whatever this second send created (for example a new walk-in customer row) and the caller
 * shows the record the first send saved.
 */
class CounterRecordAlreadySaved extends RuntimeException
{
    public function __construct(public readonly PrintingOrder|PhotographyBooking $record)
    {
        parent::__construct('This counter form was already saved.');
    }
}
