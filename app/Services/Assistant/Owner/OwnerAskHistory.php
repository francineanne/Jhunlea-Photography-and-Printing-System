<?php

namespace App\Services\Assistant\Owner;

use App\Enums\AssistantAuthor;
use App\Models\OwnerAssistantMessage;
use App\Models\User;
use App\Services\Assistant\ThreadMessage;
use Illuminate\Support\Collection;

/**
 * The owner's own Ask Lumi conversation (Phase 7E-3a, table owner_assistant_messages): the last few turns for the
 * next question, and the page of turns the history endpoint shows. Only the owner's question and Lumi's answer are
 * kept, never tool data; an owner only ever reads their own rows.
 */
class OwnerAskHistory
{
    public const PAGE = 50;

    /** @return list<ThreadMessage> the last config assistant.owner.history_messages turns, oldest first */
    public function recent(User $owner): array
    {
        return OwnerAssistantMessage::query()->where('owner_id', $owner->id)->orderByDesc('id')
            ->limit((int) config('assistant.owner.history_messages'))->get()->reverse()
            ->map(fn (OwnerAssistantMessage $row) => new ThreadMessage($row->role === OwnerAssistantMessage::OWNER ? AssistantAuthor::Customer : AssistantAuthor::Lumi, $row->text))
            ->values()->all();
    }

    /** @return Collection<int, OwnerAssistantMessage> the latest PAGE turns, oldest first */
    public function page(User $owner): Collection
    {
        return OwnerAssistantMessage::query()->where('owner_id', $owner->id)->orderByDesc('id')->limit(self::PAGE)->get()->reverse()->values();
    }

    public function append(User $owner, string $question, OwnerAskResult $result): void
    {
        foreach ([[OwnerAssistantMessage::OWNER, trim($question), null], [OwnerAssistantMessage::LUMI, $result->reply, $result->meta()]] as [$role, $text, $meta]) {
            $row = new OwnerAssistantMessage;
            $row->owner_id = $owner->id;
            $row->role = $role;
            $row->text = mb_substr($text, 0, 4000);
            $row->meta = $meta;
            $row->save();
        }
    }
}
