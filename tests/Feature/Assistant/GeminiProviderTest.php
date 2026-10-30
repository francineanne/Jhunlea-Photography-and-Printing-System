<?php

namespace Tests\Feature\Assistant;

use App\Enums\AssistantFeature;
use App\Enums\AssistantOutcome;
use App\Services\Assistant\AssistantActor;
use App\Services\Assistant\AssistantEngine;
use App\Services\Assistant\AssistantInput;
use App\Services\Assistant\Exceptions\ProviderBadResponse;
use App\Services\Assistant\Exceptions\ProviderRateLimited;
use App\Services\Assistant\Exceptions\ProviderUnavailable;
use App\Services\Assistant\Providers\GeminiProvider;
use App\Services\Assistant\Providers\ProviderMessage;
use App\Services\Assistant\Providers\ProviderRequest;
use App\Services\Assistant\Providers\ProviderResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The Gemini provider, always against Http::fake: no test reaches Google. */
class GeminiProviderTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'test-key-DO-NOT-LEAK-123';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function provider(): GeminiProvider
    {
        return new GeminiProvider(self::KEY, 'gemini-test-model', 12, 500, 0.2);
    }

    private function request(): ProviderRequest
    {
        return new ProviderRequest('System rules', [new ProviderMessage(ProviderMessage::USER, 'Magkano ang print?')], AssistantFeature::AskLumi);
    }

    private static function body(string $text = '{"intent":"answer","reply":"Hi","recommend":[]}'): array
    {
        return [
            'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => $text]]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 321, 'candidatesTokenCount' => 45, 'totalTokenCount' => 366],
        ];
    }

    public function test_it_posts_to_generate_content_with_the_key_only_in_the_header(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(self::body())]);

        $response = $this->provider()->generate($this->request());

        $this->assertSame('{"intent":"answer","reply":"Hi","recommend":[]}', $response->json);
        $this->assertSame(321, $response->inputTokens);
        $this->assertSame(45, $response->outputTokens);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->method() === 'POST'
                && $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-test-model:generateContent'
                && $request->header('x-goog-api-key') === [self::KEY]
                && ! str_contains($request->url(), self::KEY)
                && ! str_contains($request->body(), self::KEY)
                && $body['systemInstruction']['parts'][0]['text'] === 'System rules'
                && $body['contents'] === [['role' => 'user', 'parts' => [['text' => 'Magkano ang print?']]]]
                && $body['generationConfig']['responseMimeType'] === 'application/json'
                && $body['generationConfig']['maxOutputTokens'] === 500
                && $body['generationConfig']['temperature'] === 0.2
                && $body['generationConfig']['responseSchema'] === GeminiProvider::responseSchema();
        });
    }

    public function test_429_is_rate_limited_with_the_retry_after(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 429]], 429, ['Retry-After' => '42'])]);

        try {
            $this->provider()->generate($this->request());
            $this->fail('No exception');
        } catch (ProviderRateLimited $exception) {
            $this->assertSame(42, $exception->retryAfterSeconds);
        }
    }

    public function test_429_reads_the_retry_delay_from_the_body_when_there_is_no_header(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 429, 'details' => [['@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => '17s']]]], 429)]);

        try {
            $this->provider()->generate($this->request());
            $this->fail('No exception');
        } catch (ProviderRateLimited $exception) {
            $this->assertSame(18, $exception->retryAfterSeconds);
        }
    }

    public function test_server_errors_and_refused_keys_are_unavailable(): void
    {
        $statuses = [500, 503, 400, 403];
        $sequence = Http::sequence();

        foreach ($statuses as $status) {
            $sequence->push(['error' => ['message' => 'nope']], $status);
        }

        Http::fake(['*' => $sequence]);

        foreach ($statuses as $status) {
            try {
                $this->provider()->generate($this->request());
                $this->fail("No exception for {$status}");
            } catch (ProviderUnavailable $exception) {
                $this->assertStringNotContainsString(self::KEY, $exception->getMessage());
            }
        }
    }

    public function test_a_connection_timeout_is_unavailable(): void
    {
        Http::fake(['*' => Http::failedConnection()]);

        $this->expectException(ProviderUnavailable::class);
        $this->provider()->generate($this->request());
    }

    public function test_a_malformed_body_is_a_bad_response(): void
    {
        $bodies = ['not json at all', ['candidates' => []], ['candidates' => [['content' => ['parts' => [['text' => '']]]]]], ['promptFeedback' => ['blockReason' => 'SAFETY']]];
        $sequence = Http::sequence();

        foreach ($bodies as $body) {
            $sequence->push($body);
        }

        Http::fake(['*' => $sequence]);

        foreach ($bodies as $body) {
            try {
                $this->provider()->generate($this->request());
                $this->fail('No exception');
            } catch (ProviderBadResponse) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_resolver_builds_gemini_only_with_a_key(): void
    {
        config(['assistant.provider' => 'gemini', 'assistant.api_key' => null]);
        $this->assertNull(app(ProviderResolver::class)->resolve());

        config(['assistant.api_key' => '   ']);
        $this->assertNull(app(ProviderResolver::class)->resolve());

        config(['assistant.api_key' => self::KEY]);
        $this->assertInstanceOf(GeminiProvider::class, app(ProviderResolver::class)->resolve());

        config(['assistant.provider' => 'openai-something']);
        $this->assertNull(app(ProviderResolver::class)->resolve());
    }

    public function test_the_log_says_why_a_call_failed_with_safe_numbers_only(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            $logged[] = $event->context;
        });

        config(['assistant.provider' => 'gemini', 'assistant.api_key' => self::KEY]);
        $calls = 0;
        Http::fake(function () use (&$calls) {
            return match (++$calls) {
                1 => throw new ConnectionException('cURL error 60: SSL certificate problem: unable to get local issuer certificate for '.GeminiProvider::ENDPOINT),
                2 => Http::response(['error' => ['message' => 'Invalid JSON payload received.']], 400),
                3 => Http::response(['error' => ['code' => 429]], 429, ['Retry-After' => '9']),
            };
        });

        $engine = app(AssistantEngine::class);

        foreach (['198.51.100.10', '198.51.100.11', '198.51.100.12'] as $ip) {
            $engine->handle(new AssistantInput(AssistantFeature::AskLumi, AssistantActor::guest($ip), 'Hello there'));
        }

        $failures = array_values(array_filter($logged, fn ($context) => isset($context['kind'])));

        $this->assertSame(['feature' => 'ask_lumi', 'outcome' => 'fallback_unavailable', 'exception' => ProviderUnavailable::class, 'kind' => 'connection', 'curl_error' => 60], $failures[0]);
        $this->assertSame(['feature' => 'ask_lumi', 'outcome' => 'fallback_unavailable', 'exception' => ProviderUnavailable::class, 'kind' => 'http', 'http_status' => 400], $failures[1]);
        $this->assertSame(['feature' => 'ask_lumi', 'outcome' => 'fallback_unavailable', 'exception' => ProviderRateLimited::class, 'kind' => 'rate_limited', 'http_status' => 429, 'retry_after' => 9], $failures[2]);

        foreach ($failures as $context) {
            $this->assertStringNotContainsString('googleapis', json_encode($context));
            $this->assertStringNotContainsString('Invalid JSON payload', json_encode($context));
            $this->assertStringNotContainsString(self::KEY, json_encode($context));
        }
    }

    public function test_the_key_and_the_message_never_reach_the_logs(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            $logged[] = $event->message.' '.json_encode($event->context);
        });

        config(['assistant.provider' => 'gemini', 'assistant.api_key' => self::KEY]);
        Http::fake(['*' => Http::sequence()->push(['error' => 'boom'], 500)->push('garbage')]);

        $engine = app(AssistantEngine::class);
        $first = $engine->handle(new AssistantInput(AssistantFeature::AskLumi, AssistantActor::guest('198.51.100.1'), 'My secret message 123'));
        $second = $engine->handle(new AssistantInput(AssistantFeature::AskLumi, AssistantActor::guest('198.51.100.1'), 'My secret message 123'));

        $this->assertSame(AssistantOutcome::FallbackUnavailable, $first->outcome);
        $this->assertSame(AssistantOutcome::FallbackError, $second->outcome);
        $this->assertNotEmpty($logged);

        foreach ($logged as $line) {
            $this->assertStringNotContainsString(self::KEY, $line);
            $this->assertStringNotContainsString('secret message', $line);
            $this->assertStringNotContainsString('garbage', $line);
        }
    }
}
