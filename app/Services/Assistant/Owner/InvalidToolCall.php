<?php

namespace App\Services\Assistant\Owner;

use RuntimeException;

/** A plan named an unknown tool or a parameter that is unknown or invalid: never executed (Phase 7E-3a). */
class InvalidToolCall extends RuntimeException {}
