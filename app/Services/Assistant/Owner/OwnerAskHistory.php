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

    /**
     * The texts of Lumi's own answers among the last history_messages turns that passed the guards (meta verified,
     * 7E-3b), for NumberGuard: a follow-up may repeat their numbers. Never the owner's text.
     *
     * @return list<string>
     */
    public function verified(User $owner): array
    {
        return OwnerAssistantMessage::query()->where('owner_id', $owner->id)->orderByDesc('id')
            ->limit((int) config('assistant.owner.history_messages'))->get()
            ->filter(fn (OwnerAssistantMessage $row) => $row->role === OwnerAssistantMessage::LUMI && ($row->meta['verified'] ?? false) === true)
            ->reverse()->pluck('text')->values()->all();
    }

    /**
     * A page of turns, newest page first, oldest first within the page (7E-3b: cursor paging). $before is the id of
     * the oldest turn already shown; null for the latest page.
     *
     * @return array{messages: Collection<int, OwnerAssistantMessage>, older: ?int}
     */
    public function page(User $owner, ?int $before = null, int $size = self::PAGE): array
    {
        $rows = OwnerAssistantMessage::query()->where('owner_id', $owner->id)
            ->when($before !== null, fn ($query) => $query->where('id', '<', $before))
            ->orderByDesc('id')->limit($size + 1)->get();
        $more = $rows->count() > $size;
        $messages = $rows->take($size)->reverse()->values();

        return ['messages' => $messages, 'older' => $more ? $messages->first()?->id : null];
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
