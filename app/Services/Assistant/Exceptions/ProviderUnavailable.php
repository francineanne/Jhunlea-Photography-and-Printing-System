<?php

namespace App\Services\Assistant\Exceptions;

use RuntimeException;

/**
 * No key, network error, timeout or a server error. It carries only WHY, as safe numbers for the log: the kind
 * ("connection" or "http"), the HTTP status and the cURL error number (60 = no CA bundle to check the certificate,
 * 28 = timeout). Never the key, a body or the URL.
 */
class ProviderUnavailable extends RuntimeException
{
    public function __construct(
        public readonly string $kind = 'unavailable',
        public readonly ?int $httpStatus = null,
        public readonly ?int $curlError = null,
    ) {
        parent::__construct('The AI provider is unavailable ('.$kind.($httpStatus ? " HTTP {$httpStatus}" : '').($curlError ? " cURL {$curlError}" : '').').');
    }

    /** @return array<string, int|string> */
    public function logContext(): array
    {
        return array_filter(['kind' => $this->kind, 'http_status' => $this->httpStatus, 'curl_error' => $this->curlError], fn ($value) => $value !== null);
    }
}
