<?php

namespace App\Services\Assistant\Owner;

use RuntimeException;

/** A range DateRangeResolver cannot use; the message is a question for the owner (Phase 7E-3a). */
class AmbiguousDateRange extends RuntimeException {}
