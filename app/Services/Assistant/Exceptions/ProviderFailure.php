<?php

namespace App\Services\Assistant\Exceptions;

/** A failed provider call, described by safe values only. */
interface ProviderFailure
{
    /**
     * True when the provider sent an HTTP response (any status), so the call used its quota and counts toward the
     * limits. A call that never got an answer (connection, timeout, certificate, DNS) does not.
     */
    public function reachedProvider(): bool;

    /** @return array<string, int|string> what kind of failure it was, for the log; never a body, key or URL */
    public function logContext(): array;
}
