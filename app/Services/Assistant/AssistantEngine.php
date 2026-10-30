<?php

namespace App\Services\Assistant;

use App\Enums\AssistantFeature;
use App\Enums\AssistantIntent;
use App\Enums\AssistantOutcome;
use App\Models\AssistantRun;
use App\Services\Assistant\Exceptions\ProviderBadResponse;
use App\Services\Assistant\Exceptions\ProviderRateLimited;
use App\Services\Assistant\Exceptions\ProviderUnavailable;
use App\Services\Assistant\Providers\AssistantProvider;
use App\Services\Assistant\Providers\ProviderResolver;
use App\Services\Assistant\Providers\ProviderResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * THE one entry point of the AI assistant (Phase 7). handle() never throws and never returns an error page: every
 * problem (switched off, a limit, no key, the provider down or rate limiting, a reply that breaks the contract) ends in
 * one of the fixed fallback texts. Each call writes exactly one assistant_runs row with metadata only.
 *
 * Order: input checks, the off switch, the studio_away cap, the limits, the provider cooldown, then the provider.
 * Only a call that reaches the provider counts toward the limits.
 */
class AssistantEngine
{
    private ?AssistantProvider $provider = null;

    private ?ProviderResponse $response = null;

    private ?int $latencyMs = null;

    public function __construct(
        private readonly ProviderResolver $resolver,
        private readonly PromptBuilder $prompts,
        private readonly ResponseValidator $validator,
        private readonly CardBuilder $cards,
        private readonly LimitService $limits,
        private readonly ProviderCooldown $cooldown,
    ) {}

    public function handle(AssistantInput $input): AssistantResult
    {
        $this->provider = null;
        $this->response = null;
        $this->latencyMs = null;

        try {
            $result = $this->run($input);
        } catch (Throwable $exception) {
            // Never the message, the prompt or the exception text: only what kind of failure it was
            Log::warning('assistant.failed', ['feature' => $input->feature->value, 'exception' => $exception::class]);
            $result = $this->fallback($input->feature, AssistantOutcome::FallbackError);
        }

        $this->record($input, $result);

        return $result;
    }

    private function run(AssistantInput $input): AssistantResult
    {
        $message = trim($input->message);
        $max = (int) config('assistant.max_message_chars');

        if ($message === '') {
            return new AssistantResult(config('assistant.messages.empty'), AssistantOutcome::RejectedInput);
        }

        if (mb_strlen($message) > $max) {
            return new AssistantResult(str_replace(':max', (string) $max, config('assistant.messages.too_long')), AssistantOutcome::RejectedInput);
        }

        if (! config('assistant.enabled')) {
            return $this->fallback($input->feature, AssistantOutcome::FallbackDisabled);
        }

        if ($input->feature === AssistantFeature::StudioAway && $input->consecutiveAiReplies >= (int) config('assistant.limits.studio_away_max_consecutive')) {
            return new AssistantResult(config('assistant.messages.away_cap'), AssistantOutcome::AwayCapReached);
        }

        if ($limited = $this->limits->check($input->feature, $input->actor)) {
            return $this->fallback($input->feature, $limited);
        }

        if ($this->cooldown->active()) {
            return $this->fallback($input->feature, AssistantOutcome::FallbackUnavailable);
        }

        $this->provider = $this->resolver->resolve();

        if ($this->provider === null) {
            return $this->fallback($input->feature, AssistantOutcome::FallbackUnavailable);
        }

        $request = $this->prompts->build(new AssistantInput($input->feature, $input->actor, $message, $input->history, $input->consecutiveAiReplies));
        $this->limits->hit($input->actor);
        $started = hrtime(true);

        try {
            $this->response = $this->provider->generate($request);
            $reply = $this->validator->parse($this->response->json);
        } catch (ProviderRateLimited $exception) {
            $this->cooldown->start($exception->retryAfterSeconds);

            return $this->fallback($input->feature, AssistantOutcome::FallbackUnavailable, $exception);
        } catch (ProviderUnavailable $exception) {
            return $this->fallback($input->feature, AssistantOutcome::FallbackUnavailable, $exception);
        } catch (ProviderBadResponse $exception) {
            return $this->fallback($input->feature, AssistantOutcome::FallbackError, $exception);
        } finally {
            $this->latencyMs = (int) round((hrtime(true) - $started) / 1_000_000);
        }

        return match ($reply->intent) {
            AssistantIntent::OffTopic => new AssistantResult(config('assistant.messages.off_topic'), AssistantOutcome::Refused, $reply->intent),
            AssistantIntent::Handoff => new AssistantResult($reply->reply, AssistantOutcome::Handoff, $reply->intent, needsOwner: true),
            default => new AssistantResult(
                $reply->reply,
                AssistantOutcome::Answered,
                $reply->intent,
                $input->feature->showsCards() ? $this->cards->build($reply->recommend) : [],
            ),
        };
    }

    private function fallback(AssistantFeature $feature, AssistantOutcome $outcome, ProviderUnavailable|ProviderRateLimited|ProviderBadResponse|null $cause = null): AssistantResult
    {
        if ($cause !== null) {
            // The kind, HTTP status and cURL error number say why; never the body, prompt, message, key or URL
            Log::warning('assistant.provider_failed', ['feature' => $feature->value, 'outcome' => $outcome->value, 'exception' => $cause::class] + $cause->logContext());
        }

        $reply = $outcome === AssistantOutcome::FallbackLimitUser ? $feature->userLimitMessage() : $feature->fallbackMessage();

        return new AssistantResult($reply, $outcome);
    }

    private function record(AssistantInput $input, AssistantResult $result): void
    {
        // A row that cannot be written (for example the table is not migrated yet) never breaks the answer
        rescue(fn () => AssistantRun::query()->create([
            'user_id' => $input->actor->userId,
            'guest_key' => $input->actor->guestKey,
            'feature' => $input->feature,
            'provider' => mb_substr($this->provider?->name() ?? (string) config('assistant.provider'), 0, 20),
            'model' => $this->provider?->model(),
            'outcome' => $result->outcome,
            'intent' => $result->intent,
            'input_tokens' => $this->response?->inputTokens,
            'output_tokens' => $this->response?->outputTokens,
            'latency_ms' => $this->latencyMs,
        ]), null, false);
    }
}
