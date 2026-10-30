<?php

namespace App\Models;

use App\Enums\ConversationKind;
use App\Enums\MessageSender;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One conversation of a customer (Phase 7B). Nothing is fillable: ConversationService and MessageService set every
 * column themselves, never from a request. The last_* columns are kept current by those services for the lists.
 */
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => ConversationKind::class,
            'needs_owner' => 'boolean',
            'needs_owner_at' => 'datetime',
            'last_message_at' => 'datetime',
            'last_sender_type' => MessageSender::class,
            'customer_last_read_message_id' => 'integer',
            'owner_last_read_message_id' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /** @return HasMany<ChatMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function isStudio(): bool
    {
        return $this->kind === ConversationKind::Studio;
    }

    /** @param Builder<Conversation> $query */
    public function scopeStudio(Builder $query): void
    {
        $query->where('kind', ConversationKind::Studio);
    }

    /** @param Builder<Conversation> $query */
    public function scopeAssistant(Builder $query): void
    {
        $query->where('kind', ConversationKind::Assistant);
    }

    /** @param Builder<Conversation> $query */
    public function scopeForCustomer(Builder $query, User|int $customer): void
    {
        $query->where('customer_id', $customer instanceof User ? $customer->id : $customer);
    }
}
