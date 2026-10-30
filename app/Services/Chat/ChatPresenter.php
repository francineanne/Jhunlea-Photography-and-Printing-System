<?php

namespace App\Services\Chat;

use App\Enums\AssistantCardType;
use App\Enums\MessageSender;
use App\Enums\UserRole;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\PhotographyBooking;
use App\Models\PrintingOrder;
use App\Models\User;
use App\Services\Assistant\CardBuilder;
use App\Support\LocalTime;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * The JSON of conversations and messages (Phase 7B). Bodies are plain strings, never HTML. Cards are rebuilt from the
 * catalog on every read (an unpublished item disappears, a price is always current), and the orders and bookings a
 * message is about are loaded once per page: a page costs the same number of queries however many messages it holds.
 * Nobody's email, phone or user id is ever included.
 */
class ChatPresenter
{
    public function __construct(
        private readonly CardBuilder $cards,
        private readonly OwnerPresence $presence,
        private readonly MessageService $messages,
    ) {}

    /**
     * @param  Collection<int, ChatMessage>  $messages
     * @return array<string, mixed>
     */
    public function thread(Conversation $conversation, User $viewer, Collection $messages, bool $hasMoreBefore, bool $hasMoreAfter = false): array
    {
        return [
            'conversation' => $this->conversation($conversation, $viewer),
            'messages' => $this->messages($conversation, $viewer, $messages),
            'state' => $this->state($conversation, $viewer, $hasMoreBefore, $hasMoreAfter),
        ];
    }

    /** @return array<string, mixed> */
    public function conversation(Conversation $conversation, User $viewer): array
    {
        return array_filter([
            'id' => $conversation->id,
            'kind' => $conversation->kind->value,
            'customer' => $viewer->role === UserRole::Owner ? ['name' => $conversation->customer->name] : null,
            'needs_owner' => $conversation->needs_owner,
            'last_message_at' => $this->time($conversation->last_message_at),
            'last_message_preview' => $conversation->last_message_preview,
            'last_sender_type' => $conversation->last_sender_type?->value,
            'created_at' => $this->time($conversation->created_at),
        ], fn ($value) => $value !== null);
    }

    /**
     * @param  Collection<int, ChatMessage>  $messages
     * @return list<array<string, mixed>>
     */
    public function messages(Conversation $conversation, User $viewer, Collection $messages): array
    {
        $messages = (new EloquentCollection($messages->all()))->loadMissing('sender:id,name');
        $conversation->loadMissing('customer:id,name');

        $cards = $this->cards->buildMany($messages->mapWithKeys(fn (ChatMessage $message) => [$message->id => $this->references($message)])->all());
        $about = $this->aboutReferences($messages);

        return $messages->map(fn (ChatMessage $message) => [
            'id' => $message->id,
            'sender_type' => $message->sender_type->value,
            'sender_label' => $this->label($conversation, $message),
            'body' => $message->body,
            'created_at' => $this->time($message->created_at),
            'mine' => $message->sender_id !== null && $message->sender_id === $viewer->id,
            'system_kind' => $message->systemKind()?->value,
            'intent' => $message->sender_type === MessageSender::Assistant ? ($message->meta['intent'] ?? null) : null,
            'cards' => $cards[$message->id] ?? [],
            'about' => $about[$message->id] ?? null,
            'via_lumi' => isset($message->meta['from_assistant_conversation']),
            // The Ask Lumi conversation is the customer's own; the owner never opens one, so never sees its id
            'from_assistant_conversation' => $viewer->role === UserRole::Customer ? ($message->meta['from_assistant_conversation'] ?? null) : null,
        ])->values()->all();
    }

    /** @return array<string, mixed> */
    public function state(Conversation $conversation, User $viewer, bool $hasMoreBefore, bool $hasMoreAfter = false): array
    {
        $studio = null;

        if ($conversation->isStudio()) {
            $away = $this->presence->isAway();

            $studio = [
                'owner_away' => $away,
                'away_notice' => $away ? $this->presence->awayNotice() : null,
                'waiting_for_studio' => $conversation->last_sender_type === MessageSender::Customer && ($away || $conversation->needs_owner),
                'needs_owner' => $conversation->needs_owner,
            ];
        }

        return [
            'studio' => $studio,
            'unread_count' => $this->messages->unreadCount($conversation, $viewer),
            'poll' => config('chat.poll'),
            'has_more_before' => $hasMoreBefore,
            'has_more_after' => $hasMoreAfter,
        ];
    }

    /** @return array{iso: string, local: string}|null */
    public function time(mixed $value): ?array
    {
        return $value === null ? null : ['iso' => LocalTime::iso($value), 'local' => LocalTime::format($value)];
    }

    private function label(Conversation $conversation, ChatMessage $message): string
    {
        return match ($message->sender_type) {
            MessageSender::Customer => $conversation->customer?->name ?? 'Customer',
            MessageSender::Owner => $message->sender?->name ?? 'Studio',
            MessageSender::Assistant => config('assistant.name').' (AI)',
            MessageSender::System => 'Studio',
        };
    }

    /** @return list<array{type: AssistantCardType, id: int}> */
    private function references(ChatMessage $message): array
    {
        if ($message->sender_type !== MessageSender::Assistant || ! is_array($message->meta['recommend'] ?? null)) {
            return [];
        }

        $references = [];

        foreach ($message->meta['recommend'] as $item) {
            $type = AssistantCardType::tryFrom((string) ($item['type'] ?? ''));

            if ($type !== null && is_int($item['id'] ?? null)) {
                $references[] = ['type' => $type, 'id' => $item['id']];
            }
        }

        return $references;
    }

    /**
     * The order or booking each message is about, with its reference number; one query per type for the whole page.
     *
     * @param  Collection<int, ChatMessage>  $messages
     * @return array<int, array{type: string, id: int, reference: string}>
     */
    private function aboutReferences(Collection $messages): array
    {
        $about = $messages->filter(fn (ChatMessage $message) => is_array($message->meta['about'] ?? null))
            ->mapWithKeys(fn (ChatMessage $message) => [$message->id => $message->meta['about']]);

        $numbers = [];

        foreach (['printing_order' => PrintingOrder::class, 'photography_booking' => PhotographyBooking::class] as $type => $model) {
            $ids = $about->where('type', $type)->pluck('id')->unique()->values()->all();
            $numbers[$type] = $ids === [] ? [] : $model::query()->whereKey($ids)->pluck('reference_number', 'id')->all();
        }

        return $about->map(fn (array $item) => isset($numbers[$item['type']][$item['id']])
            ? ['type' => $item['type'], 'id' => (int) $item['id'], 'reference' => (string) $numbers[$item['type']][$item['id']]]
            : null)->filter()->all();
    }
}
