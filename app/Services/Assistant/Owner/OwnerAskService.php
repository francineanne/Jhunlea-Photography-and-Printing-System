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
use App\Support\MessageLanguage;
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

    /** The language of the owner's question (7E-3b): the fixed texts follow it. */
    private string $language = MessageLanguage::UNKNOWN;

    /** Earlier answers of this conversation that already passed the guards (7E-3b): their numbers may be repeated. */
    private array $verified = [];

    /**
     * @param  list<ThreadMessage>  $history  the owner's own conversation, oldest first
     * @param  list<string>  $verified  the texts of Lumi's own earlier answers that went through these guards (at most
     *                                  the last history_messages; never the owner's or a customer's text)
     */
    public function ask(User $owner, string $message, array $history = [], array $verified = []): OwnerAskResult
    {
        $this->usedBackup = false;
        $this->language = MessageLanguage::detect($message);
        $this->verified = array_slice(array_values($verified), -(int) config('assistant.owner.history_messages'));

        try {
            return $this->run($owner, trim($message), $history);
        } catch (Throwable $exception) {
            Log::warning('assistant.owner_ask_failed', ['exception' => $exception::class]);

            return new OwnerAskResult($this->text('owner_ask'), AssistantOutcome::FallbackError);
        }
    }

    /**
     * A fixed owner text in the language of the question (7E-3b): the "_fil" variant for a Filipino or Taglish
     * question when config has one, else the English text.
     */
    public function text(string $key): string
    {
        $filipino = in_array($this->language, [MessageLanguage::FIL, MessageLanguage::TAGLISH], true) ? config("assistant.messages.{$key}_fil") : null;

        return is_string($filipino) ? $filipino : (string) config("assistant.messages.{$key}");
    }

    /** The briefing written by code only: no provider call, nothing counted. */
    public function briefing(User $owner): OwnerAskResult
    {
        $result = $this->tools->get('briefing')->execute([], $owner);

        return new OwnerAskResult(Briefing::text($result), AssistantOutcome::AnsweredByCode, $result->cards, $result->links, ['briefing'], AssistantEngine::CODE);
    }

    /**
     * The briefing endpoint's JSON (7E-3b): the answer as for ask(), plus its lines each with the owner page it is about,
     * for the overview's "Today at a glance" card. One run of the briefing tool, code only, no provider call.
     *
     * @return array<string, mixed>
     */
    public function briefingJson(User $owner): array
    {
        $result = $this->tools->get('briefing')->execute([], $owner);
        $answer = new OwnerAskResult(Briefing::text($result), AssistantOutcome::AnsweredByCode, $result->cards, $result->links, ['briefing'], AssistantEngine::CODE);

        return $answer->toArray() + ['items' => Briefing::items($result)];
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
            ! config('assistant.enabled') => new OwnerAskResult($this->text('owner_ask'), AssistantOutcome::FallbackDisabled),
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

            return $this->answer($actor, $call, new OwnerAskResult($this->text('owner_ask_clarify'), AssistantOutcome::Answered), AssistantIntent::Clarify);
        }

        if ($plan->action === OwnerPlan::CLARIFY) {
            // A clarifying question may name periods ("today or this week?"), never a number the owner did not say
            $text = $this->clean($plan->question ?? '', $message, NumberGuard::RELATIVE, $this->text('owner_ask_clarify'));

            return $this->answer($actor, $call, new OwnerAskResult($text, AssistantOutcome::Answered), AssistantIntent::Clarify);
        }

        if ($plan->action === OwnerPlan::OUT_OF_SCOPE) {
            return $this->answer($actor, $call, new OwnerAskResult($this->text('owner_ask_out_of_scope'), AssistantOutcome::Refused), AssistantIntent::OffTopic);
        }

        $results = array_map(fn (array $item) => $item['tool']->execute($item['params'], $owner), $plan->calls);
        $names = $plan->toolNames();
        $cards = array_values(array_merge([], ...array_map(fn (ToolResult $result) => $result->cards, $results)));
        $links = array_values(array_unique(array_merge([], ...array_map(fn (ToolResult $result) => $result->links, $results)), SORT_REGULAR));

        if ($plan->action === OwnerPlan::WRITE_REQUEST) {
            return $this->answer($actor, $call, new OwnerAskResult($this->text('owner_ask_write'), AssistantOutcome::Refused, [], $links, $names), AssistantIntent::Answer);
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

            return new OwnerAskResult($this->text('owner_ask'), AssistantOutcome::FallbackUnavailable, $cards, $links, $names);
        }

        try {
            $reply = $this->validator->parse($phrase->response->json);
        } catch (ProviderBadResponse) {
            return $this->answer($actor, $phrase, new OwnerAskResult($this->text('owner_ask'), AssistantOutcome::FallbackError, $cards, $links, $names), null, $names);
        }

        // A reply that names the prompt's own sections is never shown (the guard is unchanged). Since 7E-3b the owner
        // gets a polite "I can't show that here" with the page links instead of the error text: the question itself
        // was fine (the first real owner eval: "what did the customers write?" made the model say "OWNER DATA")
        if ($this->leaks($reply->reply)) {
            Log::warning('assistant.prompt_leak_blocked', ['feature' => self::FEATURE->value]);

            return $this->answer($actor, $phrase, new OwnerAskResult($this->text('owner_ask_cannot_show'), AssistantOutcome::Refused, $cards, $links, $names), $reply->intent, $names);
        }

        // Earlier answers that already passed these guards may be repeated ("how much was that again?"), 7E-3b
        $facts = [...array_merge([], ...array_map(fn (ToolResult $result) => $result->facts(), $results)), ...$this->verified];
        $text = $this->clean($reply->reply, $message, $facts, $this->text('owner_ask_unverified'));

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
            $text = $this->text('owner_ask_write');
        }
        // Never "revenue", "sales" or "profit", even in a denial (8D): those sentences go, and when the owner asked for
        // one of them a fixed sentence says the system records payments only (the income) and does not track costs
        $text = app(MoneyWordGuard::class)->guard($text, $question, $this->text('owner_ask_money_words'), $fallback);

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

        return new OwnerAskResult($this->text('owner_ask'), $outcome);
    }

    /**
     * @param  list<array{label: string, url: string}>  $cards
     * @param  list<array{label: string, url: string}>  $links
     * @param  list<string>  $tools
     */
    private function limited(AssistantOutcome $outcome, array $cards = [], array $links = [], array $tools = []): OwnerAskResult
    {
        $reply = match (true) {
            $outcome === AssistantOutcome::FallbackLimitUser && $this->limits->reason === LimitService::MINUTE => $this->text('owner_ask_limit_minute'),
            $outcome === AssistantOutcome::FallbackLimitUser => $this->text('owner_ask_limit'),
            default => $this->text('owner_ask'),
        };

        return new OwnerAskResult($reply, $outcome, $cards, $links, $tools, limit: $outcome === AssistantOutcome::FallbackLimitUser ? $this->limits->reason : null);
    }
}
