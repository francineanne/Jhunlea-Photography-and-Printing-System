<?php

namespace App\Services\Chat;

use App\Models\ChatMessage;
use App\Models\Conversation;
use Illuminate\Support\Collection;

/**
 * One page of a thread, oldest first (Phase 7B): the latest page, the page before a message (load earlier), or the
 * messages after one (polling). Always config chat.page_size at most, in a fixed number of queries.
 */
class ThreadReader
{
    /** @return array{messages: Collection<int, ChatMessage>, has_more_before: bool} */
    public function page(Conversation $conversation, ?int $after = null, ?int $before = null): array
    {
        $size = (int) config('chat.page_size');
        $query = $conversation->messages()->with('sender:id,name');

        $messages = match (true) {
            $after !== null => $query->where('id', '>', $after)->orderBy('id')->limit($size)->get(),
            $before !== null => $query->where('id', '<', $before)->orderByDesc('id')->limit($size)->get()->reverse()->values(),
            default => $query->orderByDesc('id')->limit($size)->get()->reverse()->values(),
        };

        // Older messages exist before this page (for an empty poll: before the point the client already has)
        $oldest = $messages->first()?->id ?? ($after !== null ? $after + 1 : $before);
        $hasMoreBefore = $oldest !== null && $conversation->messages()->where('id', '<', $oldest)->exists();

        return ['messages' => $messages, 'has_more_before' => $hasMoreBefore];
    }
}
