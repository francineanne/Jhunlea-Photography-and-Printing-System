<?php

namespace App\Services\Chat;

use App\Enums\AssistantAuthor;
use App\Enums\AssistantFeature;
use App\Enums\AssistantOutcome;
use App\Enums\MessageSender;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Assistant\AssistantActor;
use App\Services\Assistant\AssistantEngine;
use App\Services\Assistant\AssistantInput;
use App\Services\Assistant\ThreadMessage;

/**
 * Runs Suggest reply and Summarize for one studio thread (Phase 7D). The engine gets the thread (customer messages as
 * data inside one clearly labelled user turn, see PromptBuilder) plus the shop knowledge, under the owner's own limits
 * and the owner_assist share of the daily budget. The text comes back to the owner only: nothing is stored or sent.
 */
class OwnerAssistService
{
    public function __construct(private readonly AssistantEngine $engine) {}

    /** @return array{available: bool, text: string, lines: list<string>, outcome: string} */
    public function run(Conversation $conversation, User $owner, AssistantFeature $feature): array
    {
        $thread = $this->thread($conversation);
        $latest = collect($thread)->last(fn (ThreadMessage $message) => $message->author === AssistantAuthor::Customer);

        if ($latest === null) {
            return $this->unavailable(AssistantOutcome::RejectedInput, 'There is no customer message to work on yet.');
        }

        $result = $this->engine->handle(new AssistantInput($feature, AssistantActor::user($owner->id), $latest->text, $thread));

        // Only the model's own text is a draft or a summary; a refusal, a limit or a failure is a calm notice instead.
        // A handoff is no draft: the model says it cannot help, which is nothing the owner could send
        if (! in_array($result->outcome, [AssistantOutcome::Answered, AssistantOutcome::Handoff], true)
            || ($feature === AssistantFeature::OwnerAssistSuggest && $result->outcome === AssistantOutcome::Handoff)) {
            return $this->unavailable($result->outcome, $feature->fallbackMessage());
        }

        return [
            'available' => true,
            'text' => $result->reply,
            'lines' => $feature === AssistantFeature::OwnerAssistSummarize ? $this->bullets($result->reply) : [],
            'outcome' => $result->outcome->value,
        ];
    }

    /**
     * The latest messages of the thread, oldest first, without system notices.
     *
     * @return list<ThreadMessage>
     */
    private function thread(Conversation $conversation): array
    {
        return $conversation->messages()->where('sender_type', '!=', MessageSender::System)
            ->latest('id')->limit((int) config('assistant.owner_assist_messages'))->get()->reverse()
            ->map(fn (ChatMessage $message) => new ThreadMessage(match ($message->sender_type) {
                MessageSender::Customer => AssistantAuthor::Customer,
                MessageSender::Owner => AssistantAuthor::Owner,
                default => AssistantAuthor::Lumi,
            }, $message->body))
            ->values()->all();
    }

    /**
     * The summary as its lines, without the "- " markers, at most 5. A model sometimes puts every bullet on one line
     * ("A. - B. - C."), so a dash between spaces also starts a new line; a dash inside a word (face-to-face) never does.
     *
     * @return list<string>
     */
    private function bullets(string $text): array
    {
        $parts = preg_split('/\R|(?:^|\s)[-*•]\s+/mu', $text) ?: [];
        $lines = array_values(array_filter(array_map(fn (string $line) => trim($line), $parts), fn (string $line) => $line !== ''));

        return array_slice($lines, 0, 5);
    }

    /** @return array{available: bool, text: string, lines: list<string>, outcome: string} */
    private function unavailable(AssistantOutcome $outcome, string $text): array
    {
        return ['available' => false, 'text' => $text, 'lines' => [], 'outcome' => $outcome->value];
    }
}
