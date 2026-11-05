<?php

namespace App\Services\Assistant\Owner;

use App\Enums\AssistantFeature;
use App\Enums\AssistantIntent;
use App\Enums\AssistantOutcome;
use App\Models\User;
use App\Services\Assistant\AssistantActor;
use App\Services\Assistant\AssistantEngine;
use App\Services\Assistant\AssistantRunWriter;
use App\Services\Assistant\ClaimGuard;
use App\Services\Assistant\Exceptions\ProviderBadResponse;
use App\Services\Assistant\LimitService;
use App\Services\Assistant\Owner\Tools\Briefing;
use App\Services\Assistant\ProviderCall;
use App\Services\Assistant\ProviderChain;
use App\Services\Assistant\Redactor;
use App\Services\Assistant\ReplyCleanup;
use App\Services\Assistant\ResponseValidator;
use App\Services\Assistant\ThreadMessage;
use App\Support\PlainText;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The owner's Ask Lumi (Phase 7E-3a). ask() never throws: every problem ends in a fixed text.
 *
 * Step 1 (plan): one provider call with the forced plan schema picks at most config assistant.owner.max_tools
 * read-only tools with their parameters, or asks a clarifying question, or says out_of_scope or write_request. An
 * invalid plan (unknown tool, unknown or invalid parameter) is never executed: the owner gets a clarifying question.
 * Step 2 (phrase): code runs the tools, and ONE more call phrases the answer from their results only. At most two
 * calls per question; a clarification, a refusal and the briefing need no second call.
 *
 * The failover, cooldown, counting rule and assistant_runs rows are AssistantEngine's own (ProviderChain,
 * AssistantRunWriter); the limits are LimitService's with owner_ask's own limits and budget share. The answer passes
 * the leak check, ClaimGuard's action-claim part (Lumi never DID anything), NumberGuard (every number from the tool
 * facts), PlainText and the dangling-colon cleanup.
 */
class OwnerAskService
{
    private const FEATURE = AssistantFeature::OwnerAsk;

    public function __construct(
        private readonly OwnerTools $tools,
        private readonly OwnerPromptBuilder $prompts,
        private readonly ProviderChain $chain,
        private readonly AssistantRunWriter $runs,
        private readonly LimitService $limits,
        private readonly ResponseValidator $validator,
        private readonly ClaimGuard $claims,
        private readonly NumberGuard $numbers,
        private readonly Redactor $redactor,
    ) {}

    /** Whether any call of this question was answered by the backup provider (the "Lumi Lite" badge). */
    private bool $usedBackup = false;

    /** @param list<ThreadMessage> $history the owner's own conversation, oldest first */
    public function ask(User $owner, string $message, array $history = []): OwnerAskResult
    {
        $this->usedBackup = false;

        try {
            return $this->run($owner, trim($message), $history);
        } catch (Throwable $exception) {
            Log::warning('assistant.owner_ask_failed', ['exception' => $exception::class]);

            return new OwnerAskResult(self::FEATURE->fallbackMessage(), AssistantOutcome::FallbackError);
        }
    }

    /** The briefing written by code only: no provider call, nothing counted. */
    public function briefing(User $owner): OwnerAskResult
    {
        $result = $this->tools->get('briefing')->execute([], $owner);

        return new OwnerAskResult(Briefing::text($result), AssistantOutcome::AnsweredByCode, $result->cards, $result->links, ['briefing'], AssistantEngine::CODE);
    }

    /** @param list<ThreadMessage> $history */
    private function run(User $owner, string $message, array $history): OwnerAskResult
    {
        $max = (int) config('assistant.max_message_chars');
        $actor = AssistantActor::user((int) $owner->id);

        // Like AssistantEngine, every question writes a row, a refused or limited one too (metadata only)
        $early = match (true) {
            $message === '' => new OwnerAskResult(config('assistant.messages.empty'), AssistantOutcome::RejectedInput),
            mb_strlen($message) > $max => new OwnerAskResult(str_replace(':max', (string) $max, config('assistant.messages.too_long')), AssistantOutcome::RejectedInput),
            ! config('assistant.enabled') => new OwnerAskResult(self::FEATURE->fallbackMessage(), AssistantOutcome::FallbackDisabled),
            ($limited = $this->limits->check(self::FEATURE, $actor)) !== null => $this->limited($limited),
            default => null,
        };

        if ($early !== null) {
            $this->runs->record(self::FEATURE, $actor, new ProviderCall, $early->outcome, null);

            return $early;
        }

        // Step 1: the plan
        $call = $this->chain->call(self::FEATURE, $actor, fn () => $this->prompts->plan($message, $history));

        if (! $call->answered()) {
            return $this->failed($actor, $call);
        }

        try {
            $plan = OwnerPlan::parse($call->response->json, $this->tools);
        } catch (InvalidToolCall $invalid) {
            // Never executed; the reason is a parameter or tool name, never data
            Log::info('assistant.owner_plan_rejected', ['reason' => mb_substr($invalid->getMessage(), 0, 120)]);

            return $this->answer($actor, $call, new OwnerAskResult(config('assistant.messages.owner_ask_clarify'), AssistantOutcome::Answered), AssistantIntent::Clarify);
        }

        if ($plan->action === OwnerPlan::CLARIFY) {
            // A clarifying question may name periods ("today or this week?"), never a number the owner did not say
            $text = $this->clean($plan->question ?? '', $message, NumberGuard::RELATIVE, config('assistant.messages.owner_ask_clarify'));

            return $this->answer($actor, $call, new OwnerAskResult($text, AssistantOutcome::Answered), AssistantIntent::Clarify);
        }

        if ($plan->action === OwnerPlan::OUT_OF_SCOPE) {
            return $this->answer($actor, $call, new OwnerAskResult(config('assistant.messages.owner_ask_out_of_scope'), AssistantOutcome::Refused), AssistantIntent::OffTopic);
        }

        $results = array_map(fn (array $item) => $item['tool']->execute($item['params'], $owner), $plan->calls);
        $names = $plan->toolNames();
        $cards = array_values(array_merge([], ...array_map(fn (ToolResult $result) => $result->cards, $results)));
        $links = array_values(array_unique(array_merge([], ...array_map(fn (ToolResult $result) => $result->links, $results)), SORT_REGULAR));

        if ($plan->action === OwnerPlan::WRITE_REQUEST) {
            return $this->answer($actor, $call, new OwnerAskResult(config('assistant.messages.owner_ask_write'), AssistantOutcome::Refused, [], $links, $names), AssistantIntent::Answer);
        }

        // A tool that needs the owner to say more (two customers named Maria, an unknown reference, a vague period)
        foreach ($results as $result) {
            if ($result->question !== null && $result->rows === [] && $result->data === []) {
                return $this->answer($actor, $call, new OwnerAskResult($result->question, AssistantOutcome::Answered, $cards, $links, $names), AssistantIntent::Clarify);
            }
        }

        // The briefing needs no phrasing: code writes it
        if ($names === ['briefing']) {
            return $this->answer($actor, $call, new OwnerAskResult(Briefing::text($results[0]), AssistantOutcome::Answered, $cards, $links, $names), AssistantIntent::Answer);
        }

        $this->runs->record(self::FEATURE, $actor, $call, AssistantOutcome::Answered, AssistantIntent::Answer, ['tools' => implode(',', $names)]);
        $this->usedBackup = $call->viaBackup();

        // Step 2: the phrasing, from the tool results only
        if ($limited = $this->limits->check(self::FEATURE, $actor)) {
            $this->runs->record(self::FEATURE, $actor, new ProviderCall, $limited, null, ['tools' => implode(',', $names)]);

            return $this->limited($limited, $cards, $links, $names);
        }

        $phrase = $this->chain->call(self::FEATURE, $actor, fn () => $this->prompts->phrase($message, $history, $results));

        if (! $phrase->answered()) {
            $this->runs->record(self::FEATURE, $actor, $phrase, $phrase->failure === null ? AssistantOutcome::FallbackUnavailable : ProviderChain::failureOutcome($phrase->failure), null, ['tools' => implode(',', $names)]);

            return new OwnerAskResult(self::FEATURE->fallbackMessage(), AssistantOutcome::FallbackUnavailable, $cards, $links, $names);
        }

        try {
            $reply = $this->validator->parse($phrase->response->json);
        } catch (ProviderBadResponse) {
            return $this->answer($actor, $phrase, new OwnerAskResult(self::FEATURE->fallbackMessage(), AssistantOutcome::FallbackError, $cards, $links, $names), null, $names);
        }

        if ($this->leaks($reply->reply)) {
            Log::warning('assistant.prompt_leak_blocked', ['feature' => self::FEATURE->value]);

            return $this->answer($actor, $phrase, new OwnerAskResult(self::FEATURE->fallbackMessage(), AssistantOutcome::FallbackError, $cards, $links, $names), null, $names);
        }

        $facts = array_merge([], ...array_map(fn (ToolResult $result) => $result->facts(), $results));
        $text = $this->clean($reply->reply, $message, $facts, config('assistant.messages.owner_ask_unverified'));

        return $this->answer($actor, $phrase, new OwnerAskResult($text, AssistantOutcome::Answered, $cards, $links, $names), $reply->intent, $names);
    }

    /**
     * The guards every owner answer passes (the clarifying question too): no claimed action, every number from the
     * facts or the question, plain text, no dangling colon.
     *
     * @param  list<string>  $facts
     */
    private function clean(string $text, string $question, array $facts, string $fallback): string
    {
        $text = PlainText::repair(PlainText::spaces($text));
        // An answer that claims to have DONE something ("Done! I recorded it.") is not trusted at all: the whole answer
        // becomes the fixed "I can only read" text, so a leftover "Done!" never suggests the write happened
        if ($this->claims->guardActions($text, '') !== $text) {
            $text = config('assistant.messages.owner_ask_write');
        }
        // A phone number is a number no tool fact holds, so NumberGuard removes its sentence; the redactor after it
        // still hides any phone number or email address left (no tool ever returns one)
        $text = $this->redactor->redact($this->numbers->guard($text, $facts, $question, $fallback));

        return ReplyCleanup::finish($text, $fallback);
    }

    /** True when the answer quotes a section name or rule of either prompt. */
    // Protected so the eval's guard-off test can switch it off in a subclass; behavior unchanged
    protected function leaks(string $reply): bool
    {
        foreach ([...AssistantEngine::LEAK_MARKERS, ...OwnerPromptBuilder::LEAK_MARKERS] as $marker) {
            if (stripos($reply, $marker) !== false) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string>|null $tools */
    private function answer(AssistantActor $actor, ProviderCall $call, OwnerAskResult $result, ?AssistantIntent $intent, ?array $tools = null): OwnerAskResult
    {
        $this->runs->record(self::FEATURE, $actor, $call, $result->outcome, $intent, ($tools ?? $result->tools) === [] ? [] : ['tools' => implode(',', $tools ?? $result->tools)]);

        $fixed = in_array($result->outcome, [AssistantOutcome::FallbackError, AssistantOutcome::FallbackUnavailable], true);
        $this->usedBackup = $this->usedBackup || $call->viaBackup();

        return new OwnerAskResult($result->reply, $result->outcome, $result->cards, $result->links, $result->tools,
            $fixed ? null : $call->provider?->name(), ! $fixed && $this->usedBackup);
    }

    private function failed(AssistantActor $actor, ProviderCall $call): OwnerAskResult
    {
        $outcome = $call->failure === null ? AssistantOutcome::FallbackUnavailable : ProviderChain::failureOutcome($call->failure);
        $this->runs->record(self::FEATURE, $actor, $call, $outcome, null);

        return new OwnerAskResult(self::FEATURE->fallbackMessage(), $outcome);
    }

    /**
     * @param  list<array{label: string, url: string}>  $cards
     * @param  list<array{label: string, url: string}>  $links
     * @param  list<string>  $tools
     */
    private function limited(AssistantOutcome $outcome, array $cards = [], array $links = [], array $tools = []): OwnerAskResult
    {
        $reply = $outcome === AssistantOutcome::FallbackLimitUser ? self::FEATURE->userLimitMessage() : self::FEATURE->fallbackMessage();

        return new OwnerAskResult($reply, $outcome, $cards, $links, $tools);
    }
}
