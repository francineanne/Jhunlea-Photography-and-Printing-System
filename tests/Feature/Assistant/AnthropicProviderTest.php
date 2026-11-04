<?php

namespace Tests\Feature\Assistant;

use App\Enums\AssistantFeature;
use App\Enums\AssistantOutcome;
use App\Models\AssistantRun;
use App\Services\Assistant\AssistantActor;
use App\Services\Assistant\AssistantEngine;
use App\Services\Assistant\AssistantInput;
use App\Services\Assistant\Exceptions\ProviderBadResponse;
use App\Services\Assistant\Exceptions\ProviderRateLimited;
use App\Services\Assistant\Exceptions\ProviderUnavailable;
use App\Services\Assistant\Providers\AnthropicProvider;
use App\Services\Assistant\Providers\GeminiProvider;
use App\Services\Assistant\Providers\ProviderMessage;
use App\Services\Assistant\Providers\ProviderRequest;
use App\Services\Assistant\Providers\ProviderResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Claude through the Messages API (Phase 7E-1), always against Http::fake: no test reaches Anthropic. */
class AnthropicProviderTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'test-anthropic-key-DO-NOT-LEAK-456';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function provider(): AnthropicProvider
    {
        return new AnthropicProvider(self::KEY, 'claude-test-model', '2023-06-01', 12, 500);
    }

    private function request(): ProviderRequest
    {
        return new ProviderRequest('System rules', [
            new ProviderMessage(ProviderMessage::USER, 'Magkano ang print?'),
            new ProviderMessage(ProviderMessage::MODEL, 'Depende po sa size.'),
            new ProviderMessage(ProviderMessage::USER, 'A4 po'),
        ], AssistantFeature::AskLumi);
    }

    /** @param array<string, mixed> $input */
    private static function body(array $input = ['intent' => 'answer', 'reply' => 'Hi po', 'recommend' => []], string $stop = 'tool_use'): array
    {
        return [
            'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-test-model',
            'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'reply', 'input' => $input]],
            'stop_reason' => $stop,
            'usage' => ['input_tokens' => 812, 'output_tokens' => 37],
        ];
    }

    private function useClaude(): void
    {
        config(['assistant.provider' => 'anthropic', 'assistant.fallback_provider' => null, 'assistant.anthropic.api_key' => self::KEY,
            'assistant.anthropic.model' => 'claude-test-model', 'assistant.enabled' => true]);
    }

    public function test_it_posts_the_forced_reply_tool_with_the_system_text_and_the_roles(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(self::body())]);

        $response = $this->provider()->generate($this->request());

        $this->assertSame('{"intent":"answer","reply":"Hi po","recommend":[]}', $response->json);
        $this->assertSame(812, $response->inputTokens);
        $this->assertSame(37, $response->outputTokens);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->method() === 'POST'
                && $request->url() === 'https://api.anthropic.com/v1/messages'
                && $request->header('x-api-key') === [self::KEY]
                && $request->header('anthropic-version') === ['2023-06-01']
                && ! str_contains($request->url(), self::KEY)
                && ! str_contains($request->body(), self::KEY)
                && $body['model'] === 'claude-test-model'
                && $body['max_tokens'] === 500
                && $body['system'] === 'System rules'
                && $body['messages'] === [
                    ['role' => 'user', 'content' => 'Magkano ang print?'],
                    ['role' => 'assistant', 'content' => 'Depende po sa size.'],
                    ['role' => 'user', 'content' => 'A4 po'],
                ]
                && count($body['tools']) === 1
                && $body['tools'][0]['name'] === 'reply'
                && $body['tools'][0]['input_schema'] === AnthropicProvider::inputSchema()
                && $body['tool_choice'] === ['type' => 'tool', 'name' => 'reply']
                // Claude Haiku 5.5 refuses any non-default sampling value, so none is sent
                && ! array_key_exists('temperature', $body)
                && ! array_key_exists('top_p', $body)
                && ! array_key_exists('top_k', $body);
        });
    }

    public function test_the_schema_is_the_same_contract_as_the_validator(): void
    {
        $schema = AnthropicProvider::inputSchema();

        $this->assertSame(['intent', 'reply', 'recommend'], $schema['required']);
        $this->assertSame(['answer', 'recommend', 'clarify', 'handoff', 'off_topic'], $schema['properties']['intent']['enum']);
        $this->assertSame(['service', 'package', 'theme', 'order', 'booking'], $schema['properties']['recommend']['items']['properties']['type']['enum']);
        $this->assertFalse($schema['additionalProperties']);
    }

    public function test_the_tool_input_is_found_among_other_blocks(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [
                ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'],
                ['type' => 'text', 'text' => 'Here is my answer.'],
                ['type' => 'tool_use', 'id' => 'toolu_2', 'name' => 'reply', 'input' => ['intent' => 'clarify', 'reply' => 'Anong size po?', 'recommend' => []]],
            ],
            'stop_reason' => 'tool_use',
        ])]);

        $this->assertSame('{"intent":"clarify","reply":"Anong size po?","recommend":[]}', $this->provider()->generate($this->request())->json);
    }

    public function test_each_failure_becomes_the_right_exception(): void
    {
        $cases = [
            'rate limited' => [Http::response(['type' => 'error', 'error' => ['type' => 'rate_limit_error', 'message' => 'Slow down']], 429, ['retry-after' => '17']), ProviderRateLimited::class, ['kind' => 'rate_limited', 'http_status' => 429, 'retry_after' => 17]],
            'overloaded' => [Http::response(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']], 529), ProviderUnavailable::class, ['kind' => 'http', 'http_status' => 529]],
            'server error' => [Http::response(['type' => 'error', 'error' => ['type' => 'api_error']], 500), ProviderUnavailable::class, ['kind' => 'http', 'http_status' => 500]],
            'bad key' => [Http::response(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']], 401), ProviderUnavailable::class, ['kind' => 'http', 'http_status' => 401, 'reason' => 'auth']],
            'no permission' => [Http::response(['type' => 'error', 'error' => ['type' => 'permission_error']], 403), ProviderUnavailable::class, ['kind' => 'http', 'http_status' => 403, 'reason' => 'auth']],
            'no credit' => [Http::response(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'Your credit balance is too low to access the Anthropic API.']], 400), ProviderUnavailable::class, ['kind' => 'http', 'http_status' => 400, 'reason' => 'credit']],
            'other 400' => [Http::response(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'messages: field required']], 400), ProviderUnavailable::class, ['kind' => 'http', 'http_status' => 400]],
            'not json' => [Http::response('<html>gateway</html>', 200), ProviderBadResponse::class, ['kind' => 'bad_response']],
            'no tool call' => [Http::response(['content' => [['type' => 'text', 'text' => 'Hello!']], 'stop_reason' => 'end_turn']), ProviderBadResponse::class, ['kind' => 'no_tool_use']],
            'refusal' => [Http::response(['content' => [], 'stop_reason' => 'refusal']), ProviderBadResponse::class, ['kind' => 'refusal']],
        ];

        foreach ($cases as $name => [$response, $class, $context]) {
            Http::fake(['api.anthropic.com/*' => $response]);

            try {
                $this->provider()->generate($this->request());
                $this->fail("{$name}: no exception");
            } catch (ProviderRateLimited|ProviderUnavailable|ProviderBadResponse $exception) {
                $this->assertInstanceOf($class, $exception, $name);
                $this->assertSame($context, $exception->logContext(), $name);
                $this->assertTrue($exception->reachedProvider(), $name);
                $this->assertStringNotContainsString(self::KEY, $exception->getMessage(), $name);
            }

            Http::swap(new Factory);
            Http::preventStrayRequests();
        }

        $this->assertFalse(ProviderBadResponse::refusal()->failsOver);
    }

    public function test_a_connection_failure_never_reached_the_provider(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 12000 milliseconds for https://api.anthropic.com/v1/messages'));

        try {
            $this->provider()->generate($this->request());
            $this->fail('No exception');
        } catch (ProviderUnavailable $exception) {
            $this->assertFalse($exception->reachedProvider());
            $this->assertSame(['kind' => 'connection', 'curl_error' => 28], $exception->logContext());
        }
    }

    public function test_a_missing_key_means_unavailable_and_nothing_is_sent(): void
    {
        $resolver = app(ProviderResolver::class);
        config(['assistant.anthropic.api_key' => null]);
        $this->assertNull($resolver->make('anthropic'));

        config(['assistant.anthropic.api_key' => '   ']);
        $this->assertNull($resolver->make('anthropic'));

        config(['assistant.anthropic.api_key' => self::KEY]);
        $this->assertInstanceOf(AnthropicProvider::class, $resolver->make('anthropic'));
        config(['assistant.api_key' => 'g-key']);
        $this->assertInstanceOf(GeminiProvider::class, $resolver->make('gemini'));
        $this->assertNull($resolver->make('openai-something'));

        config(['assistant.provider' => 'anthropic', 'assistant.fallback_provider' => null, 'assistant.anthropic.api_key' => null, 'assistant.enabled' => true]);
        $result = app(AssistantEngine::class)->handle(new AssistantInput(AssistantFeature::AskLumi, AssistantActor::guest('198.51.100.20'), 'Hello'));

        $this->assertSame(AssistantOutcome::FallbackUnavailable, $result->outcome);
        $this->assertSame(config('assistant.messages.unavailable'), $result->reply);
        Http::assertNothingSent();
        $this->assertNull(AssistantRun::query()->sole()->latency_ms, 'A call without a key counts toward nothing');
    }

    public function test_the_default_chain_is_claude_then_gemini(): void
    {
        // phpunit.xml empties AI_PROVIDER and AI_FALLBACK_PROVIDER: the defaults are Claude, Haiku 5.5, and no backup
        $this->assertSame('anthropic', config('assistant.provider'));
        $this->assertSame('claude-haiku-5-5', config('assistant.anthropic.model'));
        $this->assertSame('2023-06-01', config('assistant.anthropic.version'));
        $this->assertNull(config('assistant.fallback_provider'));

        config(['assistant.provider' => 'anthropic', 'assistant.fallback_provider' => 'gemini']);
        $this->assertSame(['anthropic', 'gemini'], app(ProviderResolver::class)->chain());

        config(['assistant.fallback_provider' => null]);
        $this->assertSame(['anthropic'], app(ProviderResolver::class)->chain());

        config(['assistant.provider' => 'gemini', 'assistant.fallback_provider' => 'gemini']);
        $this->assertSame(['gemini'], app(ProviderResolver::class)->chain(), 'The same provider is never asked twice');
    }

    public function test_through_the_engine_the_run_row_names_claude_and_its_tokens(): void
    {
        $this->useClaude();
        Http::fake(['api.anthropic.com/*' => Http::response(self::body(['intent' => 'answer', 'reply' => 'Open po kami Monday to Saturday.', 'recommend' => []]))]);

        $result = app(AssistantEngine::class)->handle(new AssistantInput(AssistantFeature::AskLumi, AssistantActor::guest('198.51.100.21'), 'Open ba kayo?'));

        $this->assertSame(AssistantOutcome::Answered, $result->outcome);
        $this->assertSame('Open po kami Monday to Saturday.', $result->reply);

        $run = AssistantRun::query()->sole();
        $this->assertSame('anthropic', $run->provider);
        $this->assertSame('claude-test-model', $run->model);
        $this->assertSame(812, $run->input_tokens);
        $this->assertSame(37, $run->output_tokens);
        $this->assertNull($run->fallback_from);
        $this->assertNotNull($run->latency_ms);
    }

    public function test_claude_down_and_the_real_gemini_backup_answers(): void
    {
        $this->useClaude();
        config(['assistant.fallback_provider' => 'gemini', 'assistant.api_key' => 'gemini-test-key', 'assistant.model' => 'gemini-test-model']);
        Http::fake([
            'api.anthropic.com/*' => Http::response(['type' => 'error', 'error' => ['type' => 'overloaded_error']], 529),
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => '{"intent":"answer","reply":"Backup reply po.","recommend":[]}']]]]],
                'usageMetadata' => ['promptTokenCount' => 300, 'candidatesTokenCount' => 20],
            ]),
        ]);

        $result = app(AssistantEngine::class)->handle(new AssistantInput(AssistantFeature::AskLumi, AssistantActor::guest('198.51.100.22'), 'Hello po'));

        $this->assertSame(AssistantOutcome::Answered, $result->outcome);
        $this->assertSame('Backup reply po.', $result->reply);
        Http::assertSentCount(2);

        $runs = AssistantRun::query()->orderBy('id')->get();
        $this->assertSame(['anthropic', 'gemini'], $runs->pluck('provider')->all());
        $this->assertSame(['fallback_unavailable', 'answered'], $runs->map(fn ($run) => $run->outcome->value)->all());
        $this->assertSame([null, 'anthropic'], $runs->pluck('fallback_from')->all());
    }

    public function test_no_key_and_no_prompt_text_reach_the_log(): void
    {
        $this->useClaude();
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            $logged[] = $event->message.' '.json_encode($event->context);
        });

        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key '.self::KEY]], 401)
            ->push(['type' => 'error', 'error' => ['message' => 'Your credit balance is too low']], 400)
            ->push('not json at all', 200)]);

        foreach (['198.51.100.30', '198.51.100.31', '198.51.100.32'] as $ip) {
            app(AssistantEngine::class)->handle(new AssistantInput(AssistantFeature::AskLumi, AssistantActor::guest($ip), 'Secret question about my thesis'));
        }

        $all = implode("\n", $logged);
        $this->assertStringContainsString('"reason":"auth"', $all);
        $this->assertStringContainsString('"reason":"credit"', $all);
        $this->assertStringNotContainsString(self::KEY, $all);
        $this->assertStringNotContainsString('Secret question', $all);
        $this->assertStringNotContainsString('credit balance', $all);
        $this->assertStringNotContainsString('api.anthropic.com', $all);
        $this->assertStringNotContainsString('System rules', $all);
    }
}
