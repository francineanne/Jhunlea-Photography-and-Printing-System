<?php

namespace App\Services\Assistant\Owner\Tools;

use App\Enums\MessageSender;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Assistant\Owner\OwnerTool;
use App\Services\Assistant\Owner\ToolResult;
use App\Services\Chat\StudioInbox;

/**
 * The studio inbox at a glance: how many threads wait for the owner's reply (StudioInbox's rule), which ones Lumi
 * handed over, and for each one only the customer's NAME and the time of the last message. Never a message text.
 */
class MessagesOverview extends OwnerTool
{
    public function __construct(private readonly StudioInbox $inbox) {}

    public function name(): string
    {
        return 'messages_overview';
    }

    public function description(): string
    {
        return 'Studio messages waiting for the owner: how many threads are unanswered, which Lumi handed over, customer names and last message times (never the message text).';
    }

    protected function handle(array $params, User $owner): ToolResult
    {
        $result = new ToolResult($this->name());
        $unanswered = Conversation::query()->studio()->whereNull('resolved_at')->where('last_sender_type', MessageSender::Customer);

        $result->total = $this->inbox->unansweredCount();

        foreach ((clone $unanswered)->with('customer:id,name')->orderByDesc('last_message_at')->orderByDesc('id')->limit(self::MAX_ROWS)->get() as $thread) {
            $result->rows[] = [
                'customer' => $thread->customer?->name,
                'last_message' => $result->moment($thread->last_message_at),
                'handed_over_by_lumi' => $thread->needs_owner ? 'yes' : 'no',
            ];
            $result->card($thread->customer?->name ?? 'Thread', route('owner.messages.show', $thread));
        }

        $result->data = [
            'unanswered_threads' => $result->count($result->total),
            'handed_over_by_lumi' => $result->count((clone $unanswered)->where('needs_owner', true)->count()),
        ];
        $result->link('Messages', route('owner.messages.index', ['filter' => 'unanswered']));

        return $result;
    }
}
