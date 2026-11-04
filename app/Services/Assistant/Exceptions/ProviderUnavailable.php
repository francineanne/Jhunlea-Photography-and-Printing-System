<?php

namespace App\Services\Assistant\Exceptions;

use RuntimeException;

/**
 * No key, network error, timeout or a server error. It carries only WHY, as safe values for the log: the kind
 * ("connection" or "http"), the HTTP status, the cURL error number (60 = no CA bundle to check the certificate,
 * 28 = timeout) and, for a refused key or an empty credit balance, a short reason ("auth", "credit"). Never the key, a
 * body or the URL.
 */
class ProviderUnavailable extends RuntimeException implements ProviderFailure
{
    public const HTTP = 'http';

    public function __construct(
        public readonly string $kind = 'unavailable',
        public readonly ?int $httpStatus = null,
        public readonly ?int $curlError = null,
        public readonly ?string $reason = null,
    ) {
        parent::__construct('The AI provider is unavailable ('.$kind.($httpStatus ? " HTTP {$httpStatus}" : '').($curlError ? " cURL {$curlError}" : '').($reason ? " {$reason}" : '').').');
    }

    public function reachedProvider(): bool
    {
        return $this->kind === self::HTTP;
    }

    public function logContext(): array
    {
        return array_filter(['kind' => $this->kind, 'http_status' => $this->httpStatus, 'curl_error' => $this->curlError, 'reason' => $this->reason], fn ($value) => $value !== null);
    }
}
