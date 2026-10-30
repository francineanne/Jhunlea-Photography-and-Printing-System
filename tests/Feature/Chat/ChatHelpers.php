<?php

namespace Tests\Feature\Chat;

use App\Enums\MessageSender;
use App\Enums\OwnerPresenceMode;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Chat\ConversationService;
use App\Services\Chat\MessageService;
use App\Services\Chat\OwnerPresence;

/** Small builders for the chat tests (Phase 7B). */
trait ChatHelpers
{
    protected function customer(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }

    protected function owner(array $attributes = []): User
    {
        return User::factory()->owner()->create($attributes);
    }

    protected function studio(User $customer): Conversation
    {
        return app(ConversationService::class)->studioFor($customer);
    }

    protected function askLumi(User $customer): Conversation
    {
        return app(ConversationService::class)->startAssistantConversation($customer);
    }

    protected function say(Conversation $conversation, User $customer, string $body = 'Hello studio'): ChatMessage
    {
        return app(MessageService::class)->sendFromCustomer($conversation, $customer, $body)[0];
    }

    protected function ownerSays(Conversation $conversation, User $owner, string $body = 'Hi, this is the studio'): ChatMessage
    {
        return app(MessageService::class)->sendFromOwner($conversation, $owner, $body)[0];
    }

    /** Rows written straight to the table (no limits, no notifications), for setting up long threads. */
    protected function rows(Conversation $conversation, int $count, MessageSender $sender, ?int $senderId = null, array $attributes = []): void
    {
        ChatMessage::factory()->count($count)->from($sender, $senderId)->create(['conversation_id' => $conversation->id] + $attributes);
    }

    protected function presence(OwnerPresenceMode $mode): void
    {
        app(OwnerPresence::class)->setMode($mode);
    }
}
