<?php

namespace App\Models;

use App\Enums\MessageSender;
use App\Enums\SystemMessageKind;
use Database\Factories\ChatMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message, plain text only, never edited or deleted (v1). Written only by MessageService, ConversationService and
 * LumiReplyService. meta holds references only:
 * - assistant: {intent, outcome, recommend: [{type, id}]} (never a price, name or link; cards are rebuilt when shown)
 * - system: {system_kind}
 * - customer: optionally {about: {type, id}} and {from_assistant_conversation: id}
 */
class ChatMessage extends Model
{
    /** @use HasFactory<ChatMessageFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sender_type' => MessageSender::class,
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function systemKind(): ?SystemMessageKind
    {
        return SystemMessageKind::tryFrom((string) ($this->meta['system_kind'] ?? ''));
    }
}
