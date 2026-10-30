<?php

namespace App\Services\Assistant\Providers;

use App\Enums\AssistantCardType;
use App\Enums\AssistantIntent;
use App\Services\Assistant\Exceptions\ProviderBadResponse;
use App\Services\Assistant\Exceptions\ProviderRateLimited;
use App\Services\Assistant\Exceptions\ProviderUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Google Gemini through its REST generateContent endpoint, with Laravel's Http client. The key travels ONLY in the
 * x-goog-api-key header: never in the URL, a log line or an exception message.
 */
class GeminiProvider implements AssistantProvider
{
    public const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $timeoutSeconds,
        private readonly int $maxOutputTokens,
        private readonly float $temperature,
    ) {}

    public function name(): string
    {
        return 'gemini';
    }

    public function model(): string
    {
        return $this->model;
    }

    public function generate(ProviderRequest $request): ProviderResponse
    {
        try {
            $response = Http::withHeaders(['x-goog-api-key' => $this->apiKey])
                ->acceptJson()
                ->timeout($this->timeoutSeconds)
                ->connectTimeout(min(5, $this->timeoutSeconds))
                ->post(sprintf(self::ENDPOINT, rawurlencode($this->model)), $this->body($request));
        } catch (ConnectionException $exception) {
            // Only the cURL error number is kept; the message itself also holds the URL
            throw new ProviderUnavailable('connection', curlError: preg_match('/cURL error (\d+)/', $exception->getMessage(), $match) ? (int) $match[1] : null);
        }

        if ($response->status() === 429) {
            throw new ProviderRateLimited($this->retryAfter($response));
        }

        if (! $response->successful()) {
            // 400 (bad request), 401/403 (key refused) and 5xx all mean "not usable right now" for the customer
            throw new ProviderUnavailable('http', $response->status());
        }

        $data = $response->json();
        $parts = is_array($data) ? ($data['candidates'][0]['content']['parts'] ?? null) : null;

        if (! is_array($parts)) {
            throw new ProviderBadResponse('The AI provider sent no candidate text.');
        }

        $text = implode('', array_map(fn ($part) => is_array($part) && is_string($part['text'] ?? null) ? $part['text'] : '', $parts));

        if (trim($text) === '') {
            throw new ProviderBadResponse('The AI provider sent an empty candidate.');
        }

        $usage = is_array($data['usageMetadata'] ?? null) ? $data['usageMetadata'] : [];

        return new ProviderResponse(
            $text,
            is_int($usage['promptTokenCount'] ?? null) ? $usage['promptTokenCount'] : null,
            is_int($usage['candidatesTokenCount'] ?? null) ? $usage['candidatesTokenCount'] : null,
        );
    }

    /** @return array<string, mixed> */
    private function body(ProviderRequest $request): array
    {
        return [
            'systemInstruction' => ['parts' => [['text' => $request->systemInstruction]]],
            'contents' => array_map(fn (ProviderMessage $message) => [
                'role' => $message->role,
                'parts' => [['text' => $message->text]],
            ], $request->messages),
            'generationConfig' => [
                'temperature' => $this->temperature,
                'maxOutputTokens' => $this->maxOutputTokens,
                'responseMimeType' => 'application/json',
                'responseSchema' => self::responseSchema(),
            ],
        ];
    }

    /**
     * The output contract as a Gemini response schema (OpenAPI subset). ResponseValidator checks every field again.
     *
     * @return array<string, mixed>
     */
    public static function responseSchema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'intent' => ['type' => 'STRING', 'enum' => array_column(AssistantIntent::cases(), 'value')],
                'reply' => ['type' => 'STRING'],
                'recommend' => [
                    'type' => 'ARRAY',
                    'maxItems' => 3,
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'type' => ['type' => 'STRING', 'enum' => array_column(AssistantCardType::cases(), 'value')],
                            'id' => ['type' => 'INTEGER'],
                        ],
                        'required' => ['type', 'id'],
                    ],
                ],
            ],
            'required' => ['intent', 'reply', 'recommend'],
        ];
    }

    /** Seconds to wait from the Retry-After header, else from the body's RetryInfo (for example "17s"), else null. */
    private function retryAfter(Response $response): ?int
    {
        $header = trim($response->header('Retry-After'));

        if (preg_match('/^\d{1,6}$/', $header)) {
            return (int) $header;
        }

        $details = $response->json('error.details');

        foreach (is_array($details) ? $details : [] as $detail) {
            if (is_array($detail) && is_string($detail['retryDelay'] ?? null) && preg_match('/^(\d{1,6})(?:\.\d+)?s$/', $detail['retryDelay'], $match)) {
                return (int) $match[1] + 1;
            }
        }

        return null;
    }
}
