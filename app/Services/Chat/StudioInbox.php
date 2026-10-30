<?php

namespace App\Services\Chat;

use App\Enums\MessageSender;
use App\Models\Conversation;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The owner's inbox (Phase 7B): studio threads that have at least one message, newest activity first, never an Ask
 * Lumi conversation. The unread count of every row comes from one subquery, so a page costs the same however many
 * threads or messages there are.
 */
class StudioInbox
{
    public const FILTERS = ['needs_reply', 'needs_owner', 'unread'];

    /** @return LengthAwarePaginator<int, Conversation> */
    public function page(?string $filter, ?string $search): LengthAwarePaginator
    {
        $unread = fn ($query) => $query->where('sender_type', MessageSender::Customer)
            ->whereRaw('chat_messages.id > COALESCE(conversations.owner_last_read_message_id, 0)');

        return Conversation::query()->studio()->whereNotNull('last_message_at')
            ->with('customer:id,name')
            ->withCount(['messages as unread_count' => $unread])
            ->when($filter === 'needs_reply', fn ($query) => $query->where('last_sender_type', MessageSender::Customer))
            ->when($filter === 'needs_owner', fn ($query) => $query->where('needs_owner', true))
            ->when($filter === 'unread', fn ($query) => $query->whereHas('messages', $unread))
            ->when($search !== null && $search !== '', fn ($query) => $this->search($query, $search))
            ->orderByDesc('last_message_at')->orderByDesc('id')
            ->paginate((int) config('chat.list_page_size'))
            ->withQueryString();
    }

    /** By customer name, or by an order or booking reference (PR-000001, PB-000001): that customer's thread. */
    private function search(Builder $query, string $search): void
    {
        if (preg_match('/^(PR|PB)-\d{1,9}$/i', $search)) {
            $model = strtoupper(substr($search, 0, 2)) === 'PR' ? PrintingOrder::class : PhotographyBooking::class;
            $query->whereIn('customer_id', $model::query()->where('reference_number', strtoupper($search))->select('customer_id'));

            return;
        }

        $query->whereHas('customer', fn ($customer) => $customer->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'));
    }
}
