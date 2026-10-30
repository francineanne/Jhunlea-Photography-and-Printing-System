<?php

namespace App\Services\Chat;

use App\Enums\ConversationKind;
use App\Enums\MessageSender;
use App\Enums\SystemMessageKind;
use App\Exceptions\ChatException;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Creates conversations (Phase 7B): the one studio thread of a customer, and new Ask Lumi conversations. */
class ConversationService
{
    /** The customer's studio thread, created on first use. Two requests at once still end with one row. */
    public function studioFor(User $customer): Conversation
    {
        $existing = Conversation::query()->studio()->forCustomer($customer)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->create($customer, ConversationKind::Studio);
        } catch (UniqueConstraintViolationException) {
            return Conversation::query()->studio()->forCustomer($customer)->firstOrFail();
        }
    }

    /**
     * A new Ask Lumi conversation that opens with Lumi's fixed introduction (no AI call, no assistant run), at most config
     * chat.assistant_conversations_per_day per Manila day. The lock on the customer's row keeps two requests from both
     * taking the last one.
     */
    public function startAssistantConversation(User $customer): Conversation
    {
        return DB::transaction(function () use ($customer) {
            User::query()->whereKey($customer->id)->lockForUpdate()->first();

            $today = LocalTime::dayStartUtc(Carbon::now(config('app.display_timezone'))->toDateString());

            if (Conversation::query()->assistant()->forCustomer($customer)->where('created_at', '>=', $today)->count() >= (int) config('chat.assistant_conversations_per_day')) {
                throw ChatException::tooManyConversations();
            }

            $conversation = $this->create($customer, ConversationKind::Assistant);

            $intro = new ChatMessage;
            $intro->forceFill([
                'conversation_id' => $conversation->id,
                'sender_type' => MessageSender::Assistant,
                'body' => config('chat.messages.lumi_intro'),
                'meta' => ['system_kind' => SystemMessageKind::LumiIntro->value],
            ])->save();

            self::touchLast($conversation, $intro);

            return $conversation;
        });
    }

    /** Keeps the list columns of a conversation current after a new message. */
    public static function touchLast(Conversation $conversation, ChatMessage $message): void
    {
        $conversation->forceFill([
            'last_message_at' => $message->created_at,
            'last_sender_type' => $message->sender_type,
            'last_message_preview' => Str::limit(preg_replace('/\s+/u', ' ', $message->body) ?? '', (int) config('chat.preview_chars') - 3),
        ])->save();
    }

    private function create(User $customer, ConversationKind $kind): Conversation
    {
        $conversation = new Conversation;
        $conversation->forceFill(['customer_id' => $customer->id, 'kind' => $kind, 'thread_key' => $kind->threadKey()])->save();

        return $conversation;
    }
}
