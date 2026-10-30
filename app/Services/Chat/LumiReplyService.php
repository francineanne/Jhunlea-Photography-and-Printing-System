<?php

namespace App\Services\Chat;

use App\Enums\AssistantAuthor;
use App\Enums\AssistantFeature;
use App\Enums\AssistantOutcome;
use App\Enums\MessageSender;
use App\Enums\SystemMessageKind;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Assistant\AssistantActor;
use App\Services\Assistant\AssistantEngine;
use App\Services\Assistant\AssistantInput;
use App\Services\Assistant\AssistantResult;
use App\Services\Assistant\ThreadMessage;
use Illuminate\Support\Facades\DB;

/**
 * Lets Lumi answer the newest customer message of a conversation (Phase 7B). In an Ask Lumi conversation Lumi always
 * answers; in the studio thread only while the owner is away and the thread does not wait for the owner.
 *
 * The engine runs OUTSIDE any lock (it can take seconds). Its result is stored only if, under a lock on the
 * conversation, the situation is still the same: otherwise it is thrown away. A model reply becomes an assistant
 * message holding references only; a fallback becomes a system notice, never words in Lumi's mouth. Lumi's replies
 * notify nobody.
 */
class LumiReplyService
{
    private const HISTORY = 8;

    public function __construct(
        private readonly AssistantEngine $engine,
        private readonly OwnerPresence $presence,
        private readonly MessageService $messages,
    ) {}

    public function reply(Conversation $conversation, User $customer): LumiReply
    {
        $question = $this->latestCustomerMessage($conversation);

        if ($question === null) {
            return new LumiReply(LumiReply::NOTHING_TO_DO);
        }

        $answered = $this->repliesAfter($conversation, $question);

        if ($answered !== []) {
            return new LumiReply(LumiReply::ALREADY_ANSWERED, $answered);
        }

        if (! $this->mayAnswer($conversation)) {
            return new LumiReply(LumiReply::NOT_ALLOWED);
        }

        $result = $this->engine->handle(new AssistantInput(
            $conversation->isStudio() ? AssistantFeature::StudioAway : AssistantFeature::AskLumi,
            AssistantActor::user($customer->id),
            $question->body,
            $this->history($conversation, $question),
            $conversation->isStudio() ? $this->aiRepliesSinceOwner($conversation) : 0,
        ));

        return DB::transaction(function () use ($conversation, $question, $result) {
            $locked = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);

            if ($this->repliesAfter($locked, $question) !== [] || ! $this->mayAnswer($locked)) {
                return new LumiReply(LumiReply::DISCARDED);
            }

            $stored = [];

            if ($locked->isStudio() && $this->startsAwayStretch($locked)) {
                $stored[] = $this->messages->insert($locked, MessageSender::System, null, $this->presence->awayNotice(), ['system_kind' => SystemMessageKind::AwayNotice->value]);
            }

            $stored[] = $this->store($locked, $result);
            $conversation->setRawAttributes($locked->getAttributes(), true);

            return new LumiReply(LumiReply::REPLIED, $stored);
        });
    }

    private function store(Conversation $conversation, AssistantResult $result): ChatMessage
    {
        if (in_array($result->outcome, [AssistantOutcome::Answered, AssistantOutcome::Handoff, AssistantOutcome::Refused], true)) {
            $message = $this->messages->insert($conversation, MessageSender::Assistant, null, $result->reply, [
                'intent' => $result->intent?->value,
                'outcome' => $result->outcome->value,
                'recommend' => array_map(fn (array $card) => ['type' => $card['type'], 'id' => $card['id']], $result->cards),
            ]);

            if ($result->needsOwner && $conversation->isStudio()) {
                $conversation->forceFill(['needs_owner' => true, 'needs_owner_at' => now()])->save();
            }

            return $message;
        }

        $kind = $result->outcome === AssistantOutcome::AwayCapReached ? SystemMessageKind::AwayCap : SystemMessageKind::AiUnavailable;

        return $this->messages->insert($conversation, MessageSender::System, null, $result->reply, ['system_kind' => $kind->value]);
    }

    private function mayAnswer(Conversation $conversation): bool
    {
        return ! $conversation->isStudio() || (! $conversation->needs_owner && $this->presence->isAway());
    }

    private function latestCustomerMessage(Conversation $conversation): ?ChatMessage
    {
        return $conversation->messages()->where('sender_type', MessageSender::Customer)->latest('id')->first();
    }

    /** @return list<ChatMessage> what Lumi or a notice already said after the question (or the owner, who answered it) */
    private function repliesAfter(Conversation $conversation, ChatMessage $question): array
    {
        return $conversation->messages()->where('id', '>', $question->id)->where('sender_type', '!=', MessageSender::Customer)->orderBy('id')->get()->all();
    }

    /**
     * The messages before the question, oldest first, at most 8, without system notices. Only this conversation is
     * read, so nothing of another conversation or customer can reach the prompt.
     *
     * @return list<ThreadMessage>
     */
    private function history(Conversation $conversation, ChatMessage $question): array
    {
        return $conversation->messages()->where('id', '<', $question->id)->where('sender_type', '!=', MessageSender::System)
            ->latest('id')->limit(self::HISTORY)->get()->reverse()
            ->map(fn (ChatMessage $message) => new ThreadMessage(match ($message->sender_type) {
                MessageSender::Customer => AssistantAuthor::Customer,
                MessageSender::Owner => AssistantAuthor::Owner,
                default => AssistantAuthor::Lumi,
            }, $message->body))
            ->values()->all();
    }

    private function aiRepliesSinceOwner(Conversation $conversation): int
    {
        $lastOwner = (int) $conversation->messages()->where('sender_type', MessageSender::Owner)->max('id');

        return $conversation->messages()->where('sender_type', MessageSender::Assistant)->where('id', '>', $lastOwner)->count();
    }

    /** True when Lumi has not been answering since the owner's last reply: the away notice comes first, once. */
    private function startsAwayStretch(Conversation $conversation): bool
    {
        $last = $conversation->messages()->where('sender_type', '!=', MessageSender::Customer)->latest('id')->first();

        return $last === null
            || $last->sender_type === MessageSender::Owner
            || ($last->sender_type === MessageSender::System && ! ($last->systemKind()?->partOfLumiStretch() ?? false));
    }
}
