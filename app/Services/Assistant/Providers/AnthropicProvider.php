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
 * Claude through the Anthropic Messages API (Phase 7E-1), with Laravel's Http client. The key travels ONLY in the
 * x-api-key header: never in the URL, a log line or an exception message.
 *
 * The output contract is ONE tool, "reply", that the request forces (tool_choice), so the answer always comes back as
 * that tool's input; it is handed on as a JSON string and ResponseValidator checks it like any other provider's reply.
 * No temperature is sent: Claude Haiku 5.5 answers a non-default sampling value with a 400.
 */
class AnthropicProvider implements AssistantProvider
{
    public const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    public const TOOL = 'reply';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly string $version,
        private readonly int $timeoutSeconds,
        private readonly int $maxOutputTokens,
    ) {}

    public function name(): string
    {
        return 'anthropic';
    }

    public function model(): string
    {
        return $this->model;
    }

    public function generate(ProviderRequest $request): ProviderResponse
    {
        try {
            $response = Http::withHeaders(['x-api-key' => $this->apiKey, 'anthropic-version' => $this->version])
                ->acceptJson()
                ->timeout($this->timeoutSeconds)
                ->connectTimeout(min(5, $this->timeoutSeconds))
                ->post(self::ENDPOINT, $this->body($request));
        } catch (ConnectionException $exception) {
            // Only the cURL error number is kept; the message itself also holds the URL
            throw new ProviderUnavailable('connection', curlError: preg_match('/cURL error (\d+)/', $exception->getMessage(), $match) ? (int) $match[1] : null);
        }

        if ($response->status() === 429) {
            throw new ProviderRateLimited($this->retryAfter($response));
        }

        if (! $response->successful()) {
            throw new ProviderUnavailable(ProviderUnavailable::HTTP, $response->status(), reason: $this->reason($response));
        }

        $data = $response->json();

        if (! is_array($data) || ! is_array($data['content'] ?? null)) {
            throw new ProviderBadResponse('The AI provider sent no content.');
        }

        if (($data['stop_reason'] ?? null) === 'refusal') {
            throw ProviderBadResponse::refusal();
        }

        $input = null;

        foreach ($data['content'] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === self::TOOL && is_array($block['input'] ?? null)) {
                $input = $block['input'];

                break;
            }
        }

        if ($input === null) {
            throw new ProviderBadResponse('The AI provider sent no reply tool call.', 'no_tool_use');
        }

        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];

        return new ProviderResponse(
            json_encode($input, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            is_int($usage['input_tokens'] ?? null) ? $usage['input_tokens'] : null,
            is_int($usage['output_tokens'] ?? null) ? $usage['output_tokens'] : null,
        );
    }

    /** @return array<string, mixed> */
    private function body(ProviderRequest $request): array
    {
        return [
            'model' => $this->model,
            'max_tokens' => $this->maxOutputTokens,
            'system' => $request->systemInstruction,
            'messages' => array_map(fn (ProviderMessage $message) => [
                'role' => $message->role === ProviderMessage::USER ? 'user' : 'assistant',
                'content' => $message->text,
            ], $request->messages),
            'tools' => [[
                'name' => self::TOOL,
                'description' => 'Sends your answer to the customer. Always answer through this tool, exactly once.',
                'input_schema' => self::inputSchema(),
            ]],
            'tool_choice' => ['type' => 'tool', 'name' => self::TOOL],
        ];
    }

    /**
     * The output contract as a JSON Schema for the reply tool. ResponseValidator checks every field again.
     *
     * @return array<string, mixed>
     */
    public static function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'intent' => ['type' => 'string', 'enum' => array_column(AssistantIntent::cases(), 'value')],
                'reply' => ['type' => 'string', 'description' => 'The text the customer reads. Plain text.'],
                'recommend' => [
                    'type' => 'array',
                    'maxItems' => 3,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => ['type' => 'string', 'enum' => array_column(AssistantCardType::cases(), 'value')],
                            'id' => ['type' => 'integer'],
                        ],
                        'required' => ['type', 'id'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['intent', 'reply', 'recommend'],
            'additionalProperties' => false,
        ];
    }

    /**
     * A refused key and an empty credit balance get their own short reason in the log, so the owner can tell them from
     * an outage. Only these fixed words are kept: never the body itself.
     */
    private function reason(Response $response): ?string
    {
        if (in_array($response->status(), [401, 403], true)) {
            return 'auth';
        }

        $message = $response->json('error.message');

        return $response->status() === 400 && is_string($message) && stripos($message, 'credit balance') !== false ? 'credit' : null;
    }

    /** Seconds to wait from the retry-after header, else null. */
    private function retryAfter(Response $response): ?int
    {
        $header = trim($response->header('retry-after'));

        return preg_match('/^\d{1,6}$/', $header) ? (int) $header : null;
    }
}
